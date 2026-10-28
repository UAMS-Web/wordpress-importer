# ImportWordPress Technical Reference

This document is the **technical reference** for the importer: command/runtime behavior (overview, workflow, scans, completion), class-level architecture, parsers, configuration, and edge-case internals. For a **short operator guide** (install, quick usage, mappings), start with [`README.md`](../README.md) in the repository root.

## Technical Scope

This file focuses on:

- command wiring, prompts, import loop, and completion behavior
- class responsibilities and parser/service internals
- data-shape conversion details
- mixed-content and modules-template implementation behavior
- configuration flags and low-level troubleshooting

## Overview

The `ImportWordPress` Artisan command is provided by the Composer package **`uams-web/wordpress-importer`**. In this repository, implementation code lives under `src/`; when the package is installed in a Statamic app, it is typically under `vendor/uams-web/wordpress-importer/src/`. The command imports a WordPress WXR export into Statamic with interactive prompts, content-type mapping, asset handling, and taxonomy validation.

- **Command class**: `UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress`
- **Importer services**: `UAMSWeb\WordPressImporter\Services\WordPressImport\...`
- **Registration**: `UAMSWeb\WordPressImporter\ServiceProvider` (Statamic addon service provider)

## Usage

To run the command, use the following syntax in your terminal:

```bash
php artisan statamic:import:wordpress /path/to/export.xml
```

For debugging purposes, you can clear the cache and log file before running the command:

- **Mac environment**:
  ```bash
  php artisan cache:clear && > storage/logs/laravel.log && php artisan statamic:import:wordpress /path/to/export.xml
  ```
- **Windows environment**:
  ```bash
  php artisan cache:clear && echo. > storage\logs\laravel.log && php artisan statamic:import:wordpress /path/to/export.xml
  ```

## Command Signature

- **Name**: `statamic:import:wordpress`
- **Argument**: `file` - The path to the WordPress XML export file.
- **Description**: Imports content from a WordPress XML export into Statamic.

## Key Features

1. **XML Parsing**: Utilizes `XmlParser` to read and analyze the WordPress export file, identifying content types and their counts.
2. **Dynamic Content Type Detection**: Detects all content types present in the XML file dynamically, ensuring no content type is missed during the initial scan.
3. **Alphabetical Content Type Display**: Displays content types in alphabetical order in the output table for better readability and organization.
4. **Memory Optimization**: Implements streaming parser for memory-efficient processing of large XML files, preventing memory exhaustion.
5. **Filesize Measurement**: When enabled (see `WORDPRESS_IMPORT_MEASURE_FILESIZE`), measures and reports the filesize difference of the codebase before and after import, displaying results in bytes, kilobytes, megabytes, and gigabytes.
6. **Site Selection**: Prompts the user to select a target site within Statamic for content import using `SiteSelector`.
7. **Content Type Selection**: Allows users to choose which content types to import (e.g., pages, posts, people) via `ContentTypeSelector`.
8. **Destination Mapping**: Maps each WordPress content type to the Statamic collection or global set its default mapping names. A content type with no default mapping is reported, and the run ends.
9. **Classic Block Detection**: Scans for Classic Editor content and warns users, as it is not fully compatible with Statamic's Bard format.
10. **Row Block Detection**: Scans for WordPress Row blocks (wp:group blocks with flex layout and nowrap configuration) and warns users, as they are not fully compatible with Statamic's Bard format.
11. **Details Block Detection**: Scans for WordPress Details blocks (wp:details blocks) and warns users, as they are not fully compatible with Statamic's Bard format.
12. **Freeform Block Detection**: Scans for WordPress Freeform blocks (wp:freeform blocks) and warns users, as they are not fully compatible with Statamic's Bard format.
13. **Knowledge Base Topic Detection**: Scans for Knowledge Base (`kb`) entries without associated topics and warns users, as these entries will not be imported until topics are assigned on the source site.
14. **Entry Creation**: Creates Statamic entries for imported content using `EntryCreator`, with support for custom field mapping and content processing.
15. **Taxonomy Validation**: Ensures taxonomy terms are correctly set up for the selected site with `TaxonomyValidator`.
16. **Asset Import**: Handles the import of images and files associated with content using `AssetImporter`.
17. **Site-Specific Asset Filtering**: Filters assets based on the WordPress site's base blog URL, automatically skipping assets that are not on the same site to prevent cross-site asset imports.
18. **Unique Filename Generation**: Prevents conflicts between assets with the same name from different WordPress upload directories by generating unique filenames with year-month prefixes (e.g., `2023-12-image.png`, `2024-08-image.png`).
19. **Global Set Management**: Imports data into Statamic global sets (e.g., redirects) using `GlobalCreator`.
20. **Tree Building**: Constructs navigation trees for hierarchical content like pages with `TreeBuilder`.
21. **Bard Content Sanitization**: After all content is imported, runs Bard content sanitization on all collection entries so YAML is formatted properly when the import is completed. Double-quotes text values containing `\_` (non-breaking space) and quotes YAML document separators in Bard text nodes, using `BardContentSanitizer`.

## Supported Content Types

The command directly supports importing the following content types:
- `page`
- `post`
- `uamspeople`
- `redirect_rule`
- `tablepress_table`
- `kb` (MinervaKB articles)
- `nav_menu_item` (Navigation menu items)

If unsupported content types are found in the XML, they are listed for user awareness but not processed.

### Default Mappings

The command provides default mappings for common WordPress content types to Statamic collections or global sets:

- `page` → Pages Collection (`pages`)
- `post` → Posts Collection (`post`)
- `uamspeople` → People Collection (`people`)
- `redirect_rule` → Redirects Global Set (`redirects`), or the Redirect Domains Collection (`redirect_domains`) for a listed vanity domain (wordpress-importer-archive#1306)
- `tablepress_table` → Tables Collection (`tables`)
- `nav_menu_item` → Primary Navigation (`primary_navigation`)
- `kb` → Knowledge Base Articles Collection (`knowledge_bases`)

These default mappings are automatically used when available. If no default mapping exists for a content type, users are prompted to select a custom destination.

### Navigation Menu Import

The command includes specialized support for importing WordPress navigation menu items (`nav_menu_item` posts) into Statamic's primary navigation. When the `nav_menu_item` content type is selected, the command uses the `NavigationMenuImporter` to handle the import process:

1. **Primary Navigation Confirmation**: Prompts the user to confirm if there's an active menu in the primary navigation position (labeled "White Bar" for UAMS 2016 theme or "Primary Navigation Menu" for UAMS 2020 theme)
2. **Menu Selection**: Displays available navigation menus from the WordPress export and allows the user to select which menu to import
3. **Menu Item Processing**: Processes different types of navigation menu items:
   - **Custom URLs** (`_menu_item_type: 'custom'`): Creates navigation items with custom URLs using `_menu_item_url`
   - **Post Type Links** (`_menu_item_type: 'post_type'`): Creates navigation items linking to imported Statamic entries (pages, posts, etc.)
   - **Taxonomy Links** (`_menu_item_type: 'taxonomy'`): Skipped as per requirements
4. **Hierarchical Structure**: Maintains the menu hierarchy using `_menu_item_menu_item_parent` to create parent-child relationships
5. **Collection Integration**: Automatically enables relevant collections for the primary navigation if they contain linked entries

**Important Notes for Navigation Menu Import:**
- Only imports items from the selected menu based on `category domain="nav_menu" nicename="*"`
- Clears existing navigation items for the site before importing new ones
- Provides helpful links to WordPress menu locations for verification
- Skips taxonomy-based menu items as they're not supported in the current implementation
- Maintains proper hierarchy and ordering from the original WordPress menu

### MinervaKB Import

The command includes specialized support for importing MinervaKB articles from WordPress. When the `kb` content type is selected, the command uses the `MinervaKBProcessor` to handle the hierarchical structure of MinervaKB content:

1. **Knowledge Base Entry**: Creates a top-level knowledge base entry for the site using the site name from `resources/sites.yaml`
2. **Topic Hierarchy**: Processes `wp:term` elements with `wp:term_taxonomy` of `kbtopic` to create topic entries as children of the knowledge base
3. **Article Import**: Processes `wp:post` elements with `wp:post_type` of `kb` to create article entries as children of topics
4. **Hierarchical Structure**: Maps MinervaKB's taxonomy-based structure to Statamic's hierarchical collection structure
5. **Collection Configuration**: Automatically enables the `knowledge_bases` collection for the selected site using `SiteConfigurator`, ensuring the collection and its structure are ready for content import
6. **Tree Building**: Constructs a custom tree structure for the `knowledge_bases` collection, with the knowledge base entry as the root, followed by topics and their hierarchical relationships, managed directly by `MinervaKBProcessor` instead of the general `TreeBuilder`

**Important Notes for MinervaKB Import:**
- Each article is associated with only one topic (the first topic in the article's category list)
- The processor creates a hierarchical structure where topics are children of the knowledge base entry, and articles are children of topics
- Content restrictions from MinervaKB are not automatically imported and must be manually configured in Statamic
- The processor displays a note at the end reminding users to review content restrictions on the source site
- Tree building for `kb` content is handled separately from other content types to ensure the taxonomy-based hierarchy is correctly represented in Statamic

## Workflow

1. **File Validation**: Checks if the provided XML file exists.
2. **Content Analysis**: Parses the XML file to extract content types and their counts.
3. **Site Configuration**: Automatically enables collections and taxonomies for the selected site based on content needs.
4. **Content Import Loop**:
   - Select content type to import.
   - For navigation menu items: Handle navigation import process (skip other steps).
   - Scan for Classic blocks.
   - Prompt to continue if Classic blocks are found.
   - Scan for Row blocks.
   - Prompt to continue if Row blocks are found.
   - Scan for Details blocks.
   - Prompt to continue if Details blocks are found.
   - Scan for Freeform blocks.
   - Prompt to continue if Freeform blocks are found.
   - Scan for Knowledge Base Entries without Topics.
   - Prompt to continue if Knowledge Base Entries without Topics are found.
   - Use default destination or select a custom one.
   - Automatically enable necessary collections, global sets, or taxonomies for the site.
   - Set overwrite behaviors for entries, assets, and tables.
   - Import content and validate associated taxonomies.
   - Build content trees if applicable.
5. **Completion**: When the user is done importing (declines "import more content"), the command clears the Stache, runs navigation import and deferred processing, measures filesize (when `WORDPRESS_IMPORT_MEASURE_FILESIZE` is not false), clears the selected site and form caches, clears the Laravel cache, runs Bard content sanitization on all collection entries (so YAML is formatted properly), displays import warnings, and reports "Import process complete." Until then, the command offers to import additional content types after each run.

### File Validation

The command checks if the provided XML file exists using the `file_exists()` function within `src/Console/Commands/ImportWordPress.php`. If the file is not found, an error message is displayed, and the process exits with a failure code.

### Content Analysis

The command utilizes the `XmlParser` class from `src/Services/WordPressImport/Parsers/XmlParser.php` to parse the XML file and dynamically extract all content types and their counts. The `ImportWordPress` command then displays this information in a table sorted alphabetically by content type for user awareness.

### Site Configuration

The command utilizes the `SiteConfigurator` class from `src/Services/WordPressImport/Entries/SiteConfigurator.php` to enable collections and taxonomies for the selected site based on content needs. This class is invoked post-parsing by `EntryCreator.php` to handle content-driven configuration adjustments, separate from the initial target collection enabling managed by `ImportWordPress.php` during setup.

### Content Import Loop

#### Select Content Type to Import

The command uses the `ContentTypeSelector` class from `src/Services/WordPressImport/Prompts/ContentTypeSelector.php` to prompt the user to choose a content type from the parsed XML data and ensures a valid selection is made. For more details on the `ContentTypeSelector` class, see the [Prompt Classes](#prompt-classes) section.

#### Handle Navigation Import Process

When the `nav_menu_item` content type is selected, the command uses the `NavigationMenuSelector` and `NavigationMenuImporter` classes to handle the navigation import process:

1. **Primary Navigation Confirmation**: Uses `NavigationMenuSelector::confirmActivePrimaryNavigation()` to prompt the user to confirm if there's an active menu in the primary navigation position
2. **Menu Selection**: Uses `NavigationMenuSelector::selectNavigationMenu()` to display available navigation menus and allow the user to select which menu to import
3. **Menu Import**: Uses `NavigationMenuImporter::importNavigationMenu()` to process the selected menu items and create Statamic navigation items

The navigation import process skips the standard content scanning and validation steps since navigation items have different requirements and processing logic.

#### Scan for Classic Blocks

The command uses the `scanForClassicBlocks()` method within `src/Console/Commands/ImportWordPress.php` to check each item's `<content:encoded>` for the absence of Gutenberg block comments (`<!-- wp:`) to identify Classic block content. It skips scanning for `tablepress_table` and `uamspeople` content types, as their content is not intended for Bard parsing. The method returns an array of affected pages with details such as title, ID, and link.

#### Prompt to Continue if Classic blocks are Found

After scanning for Classic blocks, if any are detected, the command displays a warning message listing the affected pages and prompts the user to decide whether to continue with the import.

#### Scan for Row blocks

The command uses the `scanForRowBlocks()` method within `src/Console/Commands/ImportWordPress.php` to check each item's `<content:encoded>` for the presence of WordPress Row blocks (`wp:group` with `flex-layout` and `nowrap` configuration) to identify Row block content. It skips scanning for `tablepress_table` and `uamspeople` content types, as their content is not intended for Bard parsing. The method returns an array of affected pages with details such as title, ID, and link.

Row blocks are a variation of the WordPress group block that arranges content horizontally using flexbox layout. They are identified by having `layout.type` set to `"flex"` and `flexWrap` set to `"nowrap"` in the block attributes.

#### Prompt to Continue if Row blocks are Found

After scanning for Row blocks, if any are detected, the command displays a warning message listing the affected pages and prompts the user to decide whether to continue with the import.

#### Scan for Details blocks

The command uses the `scanForDetailsBlocks()` method within `src/Console/Commands/ImportWordPress.php` to check each item's `<content:encoded>` for the presence of WordPress Details blocks (`wp:details` with `open` and `close` attributes) to identify Details block content. It skips scanning for `tablepress_table` and `uamspeople` content types, as their content is not intended for Bard parsing. The method returns an array of affected pages with details such as title, ID, and link.

Details blocks are a variation of the WordPress group block that allows for collapsible content. They are identified by having `layout.type` set to `"details"` and `open` and `close` attributes to control visibility.

#### Prompt to Continue if Details blocks are Found

After scanning for Details blocks, if any are detected, the command displays a warning message listing the affected pages and prompts the user to decide whether to continue with the import.

#### Scan for Freeform blocks

The command uses the `scanForFreeformBlocks()` method within `src/Console/Commands/ImportWordPress.php` to check each item's `<content:encoded>` for the presence of WordPress Freeform blocks (`wp:freeform` with `open` and `close` attributes) to identify Freeform block content. It skips scanning for `tablepress_table` and `uamspeople` content types, as their content is not intended for Bard parsing. The method returns an array of affected pages with details such as title, ID, and link.

Freeform blocks are a variation of the WordPress group block that allows for freeform content. They are identified by having `layout.type` set to `"freeform"` and `open` and `close` attributes to control visibility.

#### Prompt to Continue if Freeform blocks are Found

After scanning for Freeform blocks, if any are detected, the command displays a warning message listing the affected pages and prompts the user to decide whether to continue with the import.

#### Scan for Knowledge Base Entries without Topics

The command uses the `scanForKbWithoutTopics()` method within `src/Console/Commands/ImportWordPress.php` to check each `kb` item's categories for the presence of elements with `domain="kbtopic"` to identify if the entry has associated topics. If no such categories are found, the entry is flagged as lacking topics. The method returns an array of affected entries with details such as title, ID, and link.

#### Prompt to Continue if Knowledge Base Entries without Topics are Found

After scanning for `kb` entries without topics, if any are detected, the command displays a warning message listing the affected entries and informs the user that these entries will not be imported. It prompts the user to decide whether to continue with the import despite the missing topics, suggesting to resolve the issue on the source site by assigning topics to all entries before re-exporting the XML file.

#### Use the Default Destination

The command checks if the selected content type has a default mapping defined in the `$defaultMappings` array. If a default mapping exists, it is automatically used. If no default mapping exists, the command reports it and ends the run with an error status. Every supported content type has a default mapping, so this happens only if one is added without its mapping (#213).

#### Automatically Enable Necessary Collections, Global Sets, or Taxonomies for the Site

The command checks if the selected destination (collection or taxonomy) is enabled for the chosen site. If not, the command automatically enables it without user confirmation. This process is managed by the `SiteConfigurator` class from `src/Services/WordPressImport/Entries/SiteConfigurator.php`, which handles enabling collections and taxonomies for specific sites based on content needs. The `SiteConfigurator` class updates the YAML configuration files for collections and taxonomies to include the selected site, and creates a tree file for collections if it doesn't exist for the selected site. This ensures that the necessary structures are in place for the import process to proceed smoothly.

#### Set Overwrite Behaviors for Entries, Assets, and Tables

The command prompts the user to choose how to handle existing content during the import process. The command offers three options for each type of content: overwrite matching files, skip matching files, or create an additional file. This is done using the `select()` method from the `Laravel\Prompts` package, which provides a modern interface for user interaction on supported systems (macOS, Linux, WSL), with a fallback to Symfony Console Questions on unsupported platforms. The selected overwrite behavior is then passed to the respective service classes (`EntryCreator`, `AssetImporter`, and `GlobalCreator`) to ensure that the import process adheres to the user's preferences.

#### Import Content and Validate Associated Taxonomies

The command uses the `EntryCreator` class from `src/Services/WordPressImport/Entries/EntryCreator.php` to create Statamic entries for the imported content. The `EntryCreator` class handles the mapping of WordPress content to Statamic entries, including custom field mapping and content processing. It also manages the import of assets associated with the content using the `AssetImporter` class from `src/Services/WordPressImport/Assets/AssetImporter.php`.

After the content is imported, the command uses the `TaxonomyValidator` class from `src/Services/WordPressImport/Entries/TaxonomyValidator.php` to ensure that the taxonomy terms are correctly set up for the selected site. The `TaxonomyValidator` class checks if the taxonomy terms used in the imported content exist in the selected site, and if not, it prompts the user to create them. This ensures that the imported content is correctly associated with the appropriate taxonomy terms.

#### Build Content Trees if Applicable

The command uses the `TreeBuilder` class from `src/Services/WordPressImport/Structures/TreeBuilder.php` to construct navigation trees for hierarchical content, such as pages. The `TreeBuilder` class analyzes the imported content to identify parent-child relationships and builds a tree structure that reflects the hierarchical organization of the content. This tree structure is then saved to the appropriate YAML configuration file for the selected site, ensuring that the navigation structure is correctly represented in Statamic. The `TreeBuilder` class also handles the creation of tree files for collections if they don't exist for the selected site, ensuring that the necessary structures are in place for the import process to proceed smoothly.

### Completion

The command uses the `Artisan::call('cache:clear')` method to clear the Laravel cache, ensuring that any changes made during the import process are reflected in the application. This is important for ensuring that the imported content is correctly displayed in Statamic.

After the cache is cleared, the command prompts the user to decide whether to import additional content types. This is done using the `confirm()` method from the `Laravel\Prompts` package, which provides a modern interface for user interaction on supported systems (macOS, Linux, WSL), with a fallback to Symfony Console Questions on unsupported platforms. If the user chooses to import additional content, the command loops back to the content type selection step, allowing the user to select another content type to import. If the user chooses not to import additional content, the command completes the import process and exits.

## Overwrite Behaviors

During import, users are prompted to choose how to handle existing content:
- **Entries**: Overwrite, skip, or create additional entries for matching IDs.
- **Assets**: Overwrite, skip, or create additional files for matching assets (if asset upload is enabled).
- **Tables**: Similar options for TablePress content if detected.
- **Redirects**: Options for handling matching source URLs in global sets.

## Taxonomy and Site Enabling

The command automatically checks if required collections, global sets, or taxonomies are enabled for the selected site and enables them if needed. Examples include:
- `people_group` taxonomy for `uamspeople` content or pages with `show-people` shortcodes.
- `region` and `location_type` for pages with Find-a-Doc blocks.
- `logos_item_categories` for pages with logo list blocks.


## Dependencies

The command relies on several service classes for modular functionality:
- **[Parsers](#parser-classes)**: Classes for parsing WordPress XML data, including content types, items, and terms. Includes specialized parsers for page builder blocks and ACF content.
- **[Prompts](#prompt-classes)**: Interactive user interface classes for site selection, content type selection, and destination mapping. Provides a modern interface with fallback support.
- **[Entries](#entry-classes)**: Classes for creating and managing Statamic entries, including content mapping, taxonomy validation, and site configuration. Handles different content types (pages, posts, people) with specialized creators.
- **[MinervaKBProcessor](#minervakb-processor)**: Specialized processor for importing MinervaKB articles with hierarchical structure mapping.
- **[Assets](#asset-classes)**: Classes for importing and managing media files, including images, documents, and other attachments. Handles downloading, metadata setting, and overwrite behavior.
- **[Globals](#globals-classes)**: Classes for creating and modifying Statamic global sets, including redirects and other site-wide content. Manages global set creation with support for custom field mapping.
- **[Structures](#structure-classes)**: Classes for building and managing hierarchical content structures, particularly for collections that support tree structures. Handles parent-child relationships and navigation organization.
- **[Utilities](#general-utility-classes)**: Core utility classes for content processing, including text manipulation, link handling, and metadata mapping. Provides essential functionality for the import process.
- **[Tiptap](#tiptap-classes)**: Custom Tiptap extensions for converting WordPress content into Statamic Bard format. Includes specialized nodes and renderers for handling WordPress-specific content structures.

## Parser Classes

The `src/Services/WordPressImport/Parsers` directory contains classes that handle the parsing and conversion of WordPress content into Statamic format. These classes work together to ensure proper content mapping, block processing, and data transformation during the import process.

### XmlParser

- **Purpose**: Parses WordPress XML export files to extract content types, items, and terms using memory-efficient streaming.
- **File Path**: `src/Services/WordPressImport/Parsers/XmlParser.php`
- **Key Functionality**:
  - Loads and interprets WordPress eXtended RSS (WXR) files using streaming parser for memory efficiency.
  - Provides access to content type counts and specific item sets.
  - Manages taxonomy terms retrieval using streaming methods.
  - Excludes trashed items from counts and retrieval.
  - Supports temporary item overrides for targeted processing.
  - Uses streaming parser to prevent memory exhaustion with large XML files.
- **Methods**:
  - `parse()`: Loads and parses the XML file, registering namespaces.
  - `getContentTypes()`: Returns formatted content type counts.
  - `getItems()`: Retrieves items of a specific content type using streaming when XML hasn't been loaded.
  - `getAttachments()`: Returns empty array to prevent memory issues - attachments are handled by streaming parser.
  - `getTerms()`: Retrieves all taxonomy term items using streaming for memory efficiency.
  - `getTermsStreaming()`: Gets terms using streaming parser for large files.
  - `getBaseBlogUrl()`: Gets the site's base URL.
  - `setItems()`: Allows temporary override of items for processing.
  - `setContentTypes()`: Sets content types manually for streaming parser compatibility.
  - `setFilePath()`: Sets file path manually for streaming parser compatibility.
  - `setBaseBlogUrl()`: Sets base blog URL manually for streaming parser compatibility.

### XmlStreamParser

- **Purpose**: Provides memory-efficient streaming XML parsing for large WordPress export files.
- **File Path**: `src/Services/WordPressImport/Parsers/XmlStreamParser.php`
- **Key Functionality**:
  - Processes XML files using XMLReader for memory efficiency.
  - Streams content types, items, and terms without loading entire file into memory.
  - Prevents memory exhaustion when processing large XML files (1.7MB+).
  - Supports filtering by content type and excluding trashed items.
  - Provides generator-based iteration for efficient memory usage.
- **Methods**:
  - `__construct()`: Initializes the streaming parser with file path.
  - `streamItems()`: Streams items of a specific content type using generators.
  - `streamTerms()`: Streams taxonomy terms using generators.
  - `getContentTypeCounts()`: Counts content types without loading entire file.
  - `streamAllItems()`: Streams all `<item>` nodes without filtering by post type, excluding trashed items, used for dynamic content type detection.

### Page Builder Classes

The following classes in `src/Services/WordPressImport/Parsers/PageBuilder` handle the parsing of WordPress page builder content:

#### PageBuilderParser

- **Purpose**: Orchestrates the parsing of WordPress page builder content into Statamic format.
- **File Path**: `src/Services/WordPressImport/Parsers/PageBuilder/PageBuilderParser.php`
- **Key Functionality**:
  - Coordinates parsing between ACF and non-ACF blocks with enhanced mixed content support.
  - Manages content accumulation and finalization with robust handling of unprocessed final nodes.
  - Handles block hierarchy and relationships while preserving original content order.
  - **Static variable management**: Includes reset functionality to prevent cross-contamination between pages.
  - **Improved finalization logic**: Forces finalization for unprocessed final nodes to ensure all accumulated content is properly added to the page_builder array.
  - **Mixed content preservation**: Maintains the original order of ACF and non-ACF content as it appears in the XML, creating a unified page_builder array for landing_page blueprints.
- **Methods**:
  - `parseNode()`: Processes individual nodes in the content with enhanced finalization logic.
  - `finalizeNonAcfContent()`: Finalizes non-ACF content processing.
  - `resetStaticVariables()`: Resets static variables to prevent cross-contamination between pages.

#### AcfBlockParser

- **Purpose**: Parses WordPress ACF blocks into Statamic format.
- **File Path**: `src/Services/WordPressImport/Parsers/PageBuilder/AcfBlockParser.php`
- **Key Functionality**:
  - Handles ACF-specific block parsing.
  - Manages block attributes and inner content.
  - Coordinates with specialized block parsers.
- **Methods**:
  - `parseNode()`: Processes ACF block nodes.
  - `parseInnerContent()`: Handles nested block content.

#### NonAcfBlockParser

- **Purpose**: Parses standard WordPress blocks into Statamic format.
- **File Path**: `src/Services/WordPressImport/Parsers/PageBuilder/NonAcfBlockParser.php`
- **Key Functionality**:
  - Processes standard WordPress blocks.
  - Handles shortcodes and form blocks.
  - Manages content transformation.
  - **Static variable management**: Includes reset functionality to prevent cross-contamination between pages.
  - **Enhanced finalization**: Improved validation to only create blocks when meaningful content exists, preventing empty blocks.
- **Methods**:
  - `parseNode()`: Processes non-ACF block nodes.
  - `finalizeNonAcfContent()`: Finalizes non-ACF content with improved empty content validation.
  - `resetStaticVariables()`: Resets static variables to prevent cross-contamination between pages.

### Block Classes

The following classes in `src/Services/WordPressImport/Parsers/Blocks` handle specific block types:

#### ModulesParser

- **Purpose**: Dispatcher for parsing WordPress modules template metadata into Statamic page_builder format.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/ModulesParser.php`
- **Key Functionality**:
  - Acts as a coordinator that delegates parsing of individual module types to specialized block parsers.
  - Handles conversion of WordPress modules template data (`uamswp_modules`) to Statamic page_builder replicator sets.
  - Determines module type and routes to appropriate parser following Single Responsibility Principle.
  - Supports 12 module types with corresponding specialized parsers.
  - Tracks unsupported module types for future implementation.
- **Supported Module Types**:
  - `modules_text_stacked` → StackedParser
  - `modules_link_list` → LinkListParser
  - `modules_action_bar` → ActionBarParser
  - `modules_call_out` → CallOutParser
  - `modules_cta` → CtaParser
  - `modules_news_grid` → NewsParser
  - `modules_text_overlay` → OverlayParser
  - `modules_uams_gallery` → GalleryParser
  - `modules_uams_content` → ContentParser
  - `modules_uams_livewhale` → LiveWhaleParser
  - `modules_uams_section` → SectionParser
  - `modules_image_side` → SideBySideParser
- **Methods**:
  - `parseModules()`: Main entry point for parsing modules metadata and converting to page_builder format.
  - `extractModuleData()`: Extracts data for a specific module from the modules metadata.
  - `parseModule()`: Parses a module by delegating to the appropriate specialized parser.

#### ActionBarParser

- **Purpose**: Parses WordPress ACF action bar blocks into Statamic `uamsweb_block_action_bar` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/ActionBarParser.php`
- **Key Functionality**:
  - Converts ACF action bar block attributes into Statamic replicator sets.
  - Maps block attributes (heading, background color, sections) to `uamsweb_block_action_bar` set.
  - Handles dynamic sections in the items array.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
- **Methods**:
  - `parse()`: Maps block attributes to Statamic replicator set, handling dynamic sections.
  - `parseModule()`: Parses modules template action_bar data into Statamic page_builder format.

#### CallOutParser

- **Purpose**: Parses WordPress ACF call-out blocks into Statamic `uamsweb_block_call_out` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/CallOutParser.php`
- **Key Functionality**:
  - Converts ACF call-out block attributes into Statamic replicator sets.
  - Maps block attributes (heading, body, background color, image) to `uamsweb_block_call_out` set.
  - Processes textarea-based `call_out_body` as plain text.
  - Handles optional background images and metadata import.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
- **Methods**:
  - `parse()`: Parses a WordPress ACF call-out block into a Statamic replicator set, mapping all relevant fields and handling image import.
  - `processBody()`: Processes the call-out body textarea as plain text, decoding HTML entities.
  - `parseModule()`: Parses modules template call_out data into Statamic page_builder format.

#### ContentParser

- **Purpose**: Parses WordPress ACF uams-content blocks into Statamic `uamsweb_block_content` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/ContentParser.php`
- **Key Functionality**:
  - Converts ACF uams-content block attributes into Statamic replicator sets.
  - Maps block attributes (heading, body, background color) to `uamsweb_block_content` set.
  - Processes WYSIWYG content into Bard format, handling links, shortcodes, and images.
  - **Normalizes ACF field names using `AcfFieldNormalizer` utility** for consistent field mapping across different WordPress ACF data structures.
  - **Enhanced field mapping**: Properly maps content field names for consistent data handling.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
- **Methods**:
  - `__construct()`: Initializes the parser with dependencies for Bard, link, shortcode, text, and image processing.
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names for content blocks.
  - `getNestedStructures()`: Defines which nested structures should be processed for content blocks.
  - `parse()`: Parses a uams-content block into a Statamic replicator set, mapping fields and processing WYSIWYG content. Uses AcfFieldNormalizer for consistent field mapping.
  - `parseModule()`: Parses modules template uams_content data into Statamic page_builder format with consistent field normalization.

#### CounterListParser

- **Purpose**: Parses WordPress ACF counter-list blocks into Statamic `uamsweb_block_counter` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/CounterListParser.php`
- **Key Functionality**:
  - Converts ACF counter-list block attributes into Statamic replicator sets.
  - Maps block attributes (heading, description, start, background color) to `uamsweb_block_counter` set.
  - Processes counter list sections into items with title, unit, rate, and start fields.
  - Handles textarea fields as plain text and manages date/time fields.
  - **Normalizes ACF field names using `AcfFieldNormalizer` utility** for consistent field mapping across different WordPress ACF data structures.
  - **Enhanced field mapping**: Properly maps counter list field names for consistent data handling.
- **Methods**:
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names for counter list blocks.
  - `getNestedStructures()`: Defines which nested structures should be processed for counter list blocks.
  - `parse()`: Parses a counter-list block into a Statamic replicator set, mapping fields and processing sections. Uses AcfFieldNormalizer for consistent field mapping.
  - `processBody()`: Processes textarea content as plain text, decoding HTML entities.
  - `normalizeSections()`: Transforms flat key-value array into an array of section arrays.
  - `processItems()`: Converts counter list sections into Statamic items replicator sets.

#### CtaParser

- **Purpose**: Parses WordPress ACF CTA blocks into Statamic `uamsweb_block_cta` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/CtaParser.php`
- **Key Functionality**:
  - Converts ACF CTA block attributes into Statamic replicator sets.
  - Maps block attributes (heading, body, action type, layout, size, background) to `uamsweb_block_cta` set.
  - Processes WYSIWYG content with wpautop formatting, shortcodes, and link preprocessing.
  - Handles action types (URL, phone, none) and optional background images.
  - **Normalizes ACF field names using `AcfFieldNormalizer` utility** for consistent field mapping across different WordPress ACF data structures.
  - **Enhanced field mapping**: Properly maps CTA field names for consistent data handling.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
- **Methods**:
  - `__construct()`: Initializes the parser with dependencies for Bard, link, shortcode, and wpautop processing.
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names for CTA blocks.
  - `getNestedStructures()`: Defines which nested structures should be processed for CTA blocks.
  - `parse()`: Parses a CTA block into a Statamic replicator set, mapping fields and processing WYSIWYG content. Uses AcfFieldNormalizer for consistent field mapping.
  - `processBody()`: Processes WYSIWYG body content into Bard format, handling shortcodes, wpautop formatting, and link preprocessing.
  - `parseModule()`: Parses modules template cta data into Statamic page_builder format with consistent field normalization.

#### FindADocLocationsParser

- **Purpose**: Parses WordPress ACF Find-a-Doc Locations blocks into Statamic `uamsweb_block_fad_locations` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/FindADocLocationsParser.php`
- **Key Functionality**:
  - Converts ACF Find-a-Doc Locations block attributes into Statamic replicator sets.
  - Maps block attributes (heading, description, filters, background color) to `uamsweb_block_fad_locations` set.
  - Validates required fields and tracks taxonomy terms for validation.
  - Resolves expertise and locations post IDs to `<post_name>-<post_id>` format.
  - **Normalizes ACF field names using `AcfFieldNormalizer` utility** for consistent field mapping across different WordPress ACF data structures.
  - **Enhanced field mapping**: Properly maps field-based ACF field names to standard field names for consistent data handling.
- **Methods**:
  - `parse()`: Parses a Find-a-Doc Locations block into a Statamic replicator set, mapping fields and resolving post IDs. Uses AcfFieldNormalizer for consistent field mapping.
  - `getTermSlug()`: Retrieves the slug for a taxonomy term by its ID and taxonomy type.
  - `getPostName()`: Retrieves the post name for a given post ID and post type from XML items.
  - `getTermsUsed()`: Returns the terms used in parsed blocks for taxonomy validation.
  - `resetTermsUsed()`: Resets the terms used for a new import cycle.
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names for Find-a-Doc Locations blocks.
  - `getNestedStructures()`: Defines which nested structures should be processed for Find-a-Doc Locations blocks.

#### FindADocProvidersParser

- **Purpose**: Parses WordPress ACF Find-a-Doc Providers blocks into Statamic `uamsweb_block_fad_providers` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/FindADocProvidersParser.php`
- **Key Functionality**:
  - Converts ACF Find-a-Doc Providers block attributes into Statamic replicator sets.
  - Maps block attributes (heading, description, filters, background color) to `uamsweb_block_fad_providers` set.
  - Processes post IDs for expertise, locations, and providers into Statamic entry IDs.
  - Handles region taxonomy terms with full hierarchical slugs.
  - Validates required fields and tracks region terms for validation.
  - **Normalizes ACF field names using `AcfFieldNormalizer` utility** for consistent field mapping across different WordPress ACF data structures.
  - **Enhanced field mapping**: Properly maps field-based ACF field names to standard field names for consistent data handling.
- **Methods**:
  - `parse()`: Parses a Find-a-Doc Providers block into a Statamic replicator set, mapping fields and resolving post IDs. Uses AcfFieldNormalizer for consistent field mapping.
  - `buildTermMap()`: Builds a map of term IDs to their slugs and parent IDs from XML terms.
  - `getFullTermSlug()`: Constructs the full slug path for a term, recursively prepending ancestor slugs.
  - `getPostName()`: Retrieves the post name for a given post ID and post type from XML items.
  - `getTermsUsed()`: Returns the region taxonomy terms used by Find-a-Doc Providers blocks with their page slugs.
  - `resetTermsUsed()`: Resets the terms used array for testing or cleanup.
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names for Find-a-Doc Providers blocks.
  - `getNestedStructures()`: Defines which nested structures should be processed for Find-a-Doc Providers blocks.

#### GalleryParser

- **Purpose**: Parses WordPress ACF uams-gallery blocks into Statamic `uamsweb_block_gallery` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/GalleryParser.php`
- **Key Functionality**:
  - Converts ACF gallery block attributes into Statamic replicator sets.
  - Maps block attributes (heading, description, images, columns, background) to `uamsweb_block_gallery` set.
  - Processes WYSIWYG content into Bard format, handling shortcodes and wpautop formatting.
  - Imports and validates gallery images (min 2, max 6).
  - Handles conditional "more" link fields.
  - **Normalizes ACF field names using `AcfFieldNormalizer` utility** for consistent field mapping across different WordPress ACF data structures.
  - **Enhanced field mapping**: Properly maps gallery field names for consistent data handling.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
- **Methods**:
  - `__construct()`: Initializes the parser with dependencies for WpAutoP, Bard, and shortcode processing.
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names for gallery blocks.
  - `getNestedStructures()`: Defines which nested structures should be processed for gallery blocks.
  - `parse()`: Parses a gallery block into a Statamic replicator set, mapping fields and processing images. Uses AcfFieldNormalizer for consistent field mapping.
  - `processWysiwyg()`: Processes WYSIWYG content into Bard format, handling shortcodes and formatting.
  - `processImages()`: Processes gallery images, importing them and validating the count.
  - `parseModule()`: Parses modules template uams_gallery data into Statamic page_builder format with consistent field normalization.

#### LinkListParser

- **Purpose**: Parses WordPress ACF link-list blocks into Statamic `uamsweb_block_link_list` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/LinkListParser.php`
- **Key Functionality**:
  - Converts ACF link-list block attributes into Statamic replicator sets.
  - Maps block attributes (heading, description, sections) to `uamsweb_block_link_list` set.
  - Processes textarea fields as plain text.
  - Handles link sections with title, body, URL, and description fields.
  - **Normalizes ACF field names using `AcfFieldNormalizer` utility** for consistent field mapping across different WordPress ACF data structures.
  - **Enhanced field mapping**: Properly maps link list field names for consistent data handling.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
- **Methods**:
  - `parse()`: Parses a link-list block into a Statamic replicator set, mapping fields and processing sections. Uses AcfFieldNormalizer for consistent field mapping.
  - `processBody()`: Processes textarea content as plain text, decoding HTML entities.
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names for link list blocks.
  - `getNestedStructures()`: Defines which nested structures should be processed for link list blocks.
  - `normalizeSections()`: Transforms flat key-value array into an array of section arrays.
  - `processItems()`: Converts link sections into Statamic items replicator sets.
  - `parseModule()`: Parses modules template link_list data into Statamic page_builder format with consistent field normalization.

#### LiveWhaleParser

- **Purpose**: Parses WordPress ACF LiveWhale Calendar blocks into Statamic `uamsweb_block_livewhale` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/LiveWhaleParser.php`
- **Key Functionality**:
  - Converts ACF LiveWhale Calendar block attributes into Statamic replicator sets.
  - Maps block attributes (heading, widget ID, background color) to `uamsweb_block_livewhale` set.
  - Processes text fields and handles widget ID as an integer.
  - Manages optional description field.
  - **Normalizes ACF field names using `AcfFieldNormalizer` utility** for consistent field mapping across different WordPress ACF data structures.
  - **Enhanced field mapping**: Properly maps LiveWhale field names for consistent data handling.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
  - **Raw HTML (div.lwcw)**: When content contains only LiveWhale widget HTML (e.g., `<div class="lwcw" data-options="id=20&format=html"></div>`) with no Gutenberg block comments, `PageEntryCreator::hasPageBuilderBlocks()` and `ContentProcessor::hasAcfBlocksInContent()` detect it so the page is treated as a landing page and `AcfBlockParser` parses the div into a `uamsweb_block_livewhale` set with a placeholder heading.
- **Methods**:
  - `parse()`: Parses a LiveWhale Calendar block into a Statamic replicator set, mapping fields and validating widget ID. Uses AcfFieldNormalizer for consistent field mapping.
  - `processBody()`: Processes textarea content as plain text, decoding HTML entities.
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names for livewhale blocks.
  - `getNestedStructures()`: Defines which nested structures should be processed for livewhale blocks.
  - `parseModule()`: Parses modules template uams_livewhale data into Statamic page_builder format with consistent field normalization.

#### LogoListParser

- **Purpose**: Parses WordPress ACF Logo List blocks into Statamic `uamsweb_block_logo_list` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/LogoListParser.php`
- **Key Functionality**:
  - Converts ACF Logo List block attributes into Statamic replicator sets.
  - Maps block attributes (heading, description, filters, layout) to `uamsweb_block_logo_list` set.
  - Processes taxonomy terms for categories and labels.
  - Handles sort orders and conditional fields for layout and "more" link.
  - Tracks taxonomy terms for validation.
- **Methods**:
  - `parse()`: Parses a Logo List block into a Statamic replicator set, mapping fields and processing taxonomy terms.
  - `buildTermMap()`: Builds a map of term IDs to their slugs and parent IDs from XML terms.
  - `getFullTermSlug()`: Constructs the full slug path for a term, recursively prepending ancestor slugs.
  - `mapSortOrders()`: Maps WordPress sort order values to Statamic sort_option replicator sets.
  - `getTermsUsed()`: Returns the taxonomy terms used by Logo List blocks with their page slugs.
  - `resetTermsUsed()`: Resets the terms used array for testing or cleanup.

#### NewsParser

- **Purpose**: Parses WordPress ACF uams-news blocks into Statamic `uamsweb_block_news` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/NewsParser.php`
- **Key Functionality**:
  - Converts ACF news block attributes into Statamic replicator sets.
  - Maps block attributes (format, heading, category, article ID) to `uamsweb_block_news` set.
  - Handles conditional fields based on format (headlines, full, side).
  - Processes text fields and manages display options.
  - **Normalizes ACF field names using `AcfFieldNormalizer` utility** for consistent field mapping across different WordPress ACF data structures.
  - **Enhanced field mapping**: Properly maps `news_hide_title` field to `hide_block_heading` for correct visibility control.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
- **Methods**:
  - `parse()`: Parses a news block into a Statamic replicator set, mapping fields and handling format-specific options. Uses AcfFieldNormalizer for consistent field mapping.
  - `processBody()`: Processes textarea content as plain text, decoding HTML entities.
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names for news blocks.
  - `getNestedStructures()`: Defines which nested structures should be processed for news blocks.
  - `parseModule()`: Parses modules template news_grid data into Statamic page_builder format with consistent field normalization.

#### OverlayParser

- **Purpose**: Parses WordPress ACF text-overlay blocks into Statamic `uamsweb_block_overlay` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/OverlayParser.php`
- **Key Functionality**:
  - Converts ACF text-overlay block attributes into Statamic replicator sets.
  - Maps block attributes (sections) to `uamsweb_block_overlay` set.
  - Processes textarea fields as plain text.
  - Handles section items with heading, body, button, and image fields.
  - Imports and manages background images.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
- **Methods**:
  - `parse()`: Parses a text-overlay block into a Statamic replicator set, mapping fields and processing sections.
  - `processBody()`: Processes textarea content as plain text, decoding HTML entities.
  - `normalizeSections()`: Transforms flat key-value array into an array of section arrays.
  - `processItems()`: Converts overlay sections into Statamic items replicator sets.
  - `parseModule()`: Parses modules template text_overlay data into Statamic page_builder format.

#### SectionParser

- **Purpose**: Parses WordPress ACF uams-section blocks into Statamic `uamsweb_block_content` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/SectionParser.php`
- **Key Functionality**:
  - Converts ACF section block attributes and InnerBlocks into Statamic replicator sets.
  - Maps block attributes (heading, hide_heading, background) to `uamsweb_block_content` set.
  - Processes InnerBlocks into Bard format using TextProcessor.
  - Handles shortcodes, links, and images in content.
  - **Empty block prevention**: Returns null instead of creating a block if both the heading is empty (or the default placeholder) and the body is empty, preventing erroneous empty blocks in imported content.
  - **Normalizes ACF field names using `AcfFieldNormalizer` utility** for consistent field mapping across different WordPress ACF data structures.
  - **Enhanced field mapping**: Properly maps `section_hide_heading` field to `hide_block_heading` for correct visibility control.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
- **Methods**:
  - `__construct()`: Initializes the parser with dependencies for Bard, link, shortcode, text, and image processing.
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names for section blocks.
  - `getNestedStructures()`: Defines which nested structures should be processed for section blocks.
  - `parse()`: Parses a section block into a Statamic replicator set, mapping fields and processing InnerBlocks. Returns null for empty blocks. Includes enhanced logging for debugging field mappings.
  - `parseModule()`: Parses modules template uams_section data into Statamic page_builder format with consistent field normalization.

#### SideBySideParser

- **Purpose**: Parses WordPress ACF Side-by-Side Image and Text blocks into Statamic `uamsweb_block_image_side_by_side` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/SideBySideParser.php`
- **Key Functionality**:
  - Converts ACF Side-by-Side block attributes into Statamic replicator sets.
  - Maps block attributes (heading, text layout, body, image, CTA) to `uamsweb_block_image_side_by_side` set.
  - Processes textarea fields as plain text.
  - Handles link list repeater with text, URL, and description fields.
  - Imports and manages images with metadata.
  - Manages conditional fields based on text layout.
  - Normalizes ACF field names using `AcfFieldNormalizer` utility.
  - Handles multiple WordPress ACF data structures for repeater fields.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
- **Methods**:
  - `parse()`: Parses a Side-by-Side block into a Statamic replicator set, mapping fields and processing content.
  - `processBody()`: Processes textarea content as plain text, decoding HTML entities.
  - `normalizeLinkListData()`: Normalizes link list data from different WordPress ACF formats (row-based, indexed, count-only).
  - `processLinkList()`: Converts link list repeater into Statamic link_list replicator sets.
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names.
  - `getNestedStructures()`: Defines which nested structures should be processed for side-by-side blocks.
  - `parseModule()`: Parses modules template image_side data into Statamic page_builder format.

#### StackedParser

- **Purpose**: Parses WordPress ACF text-stacked blocks into Statamic `uamsweb_block_stacked` replicator sets.
- **File Path**: `src/Services/WordPressImport/Parsers/Blocks/StackedParser.php`
- **Key Functionality**:
  - Converts ACF text-stacked block attributes into Statamic replicator sets.
  - Maps block attributes (heading, hide_heading, description, sections, more) to `uamsweb_block_stacked` set.
  - Processes textarea fields as plain text.
  - Handles stacked section repeater with heading, body, image, and button fields.
  - Imports and manages images with metadata.
  - Manages conditional "more" link fields.
  - **Normalizes ACF field names using `AcfFieldNormalizer` utility** for consistent field mapping across different WordPress ACF data structures.
  - **Enhanced field mapping**: Properly maps `stacked_hide_heading` field to `hide_block_heading` for correct visibility control.
  - **Modules Template Support**: Processes modules template data via `parseModule()` method.
- **Methods**:
  - `parse()`: Parses a text-stacked block into a Statamic replicator set, mapping fields and processing sections.
  - `processBody()`: Processes textarea content as plain text, decoding HTML entities.
  - `getFieldMappings()`: Defines the mapping between field-based ACF field names and standard field names.
  - `getNestedStructures()`: Defines which nested structures should be processed for stacked blocks.
  - `normalizeSections()`: Transforms flat key-value array into an array of section arrays.
  - `processItems()`: Converts stacked sections into Statamic items replicator sets.
  - `parseModule()`: Parses modules template text_stacked data into Statamic page_builder format with consistent field normalization.

### Utility Classes

The following classes in `src/Services/WordPressImport/Parsers/Utilities` provide supporting functionality for the parsing process:

#### Alignment

- **Purpose**: Translates WordPress alignment values to Statamic equivalents.
- **File Path**: `src/Services/WordPressImport/Parsers/Utilities/Alignment.php`
- **Key Functionality**:
  - Maps WordPress alignment attributes to Statamic options.
  - Supports context-specific mappings.
  - Handles class string extraction.
- **Methods**:
  - `translate()`: Converts WordPress alignment to Statamic format.

## Prompt Classes

The `ImportWordPress` command uses several prompt classes to handle user interactions for configuring the import process. These classes are located in `src/Services/WordPressImport/Prompts/` and are designed to provide a seamless experience for selecting sites, content types, and destinations.

These prompt classes ensure that users can interactively configure the import process, making decisions about where and how content is imported into Statamic, while handling necessary configurations like site enabling behind the scenes.

All prompt classes support Laravel Prompts for a better user experience on compatible systems (macOS, Linux, WSL), with a fallback to Symfony Console Questions on unsupported platforms (Windows without WSL). This ensures a consistent and user-friendly interface across different operating systems.

Below is a detailed overview of each class:

### SiteSelector

- **Purpose**: Handles the selection and validation of a Statamic site for the WordPress content import.
- **File Path**: `src/Services/WordPressImport/Prompts/SiteSelector.php`
- **Key Functionality**:
  - Prompts the user to choose a site from Statamic's configured sites or a fallback configuration file (`resources/sites.yaml`).
  - Falls back to a text input prompt if no configured sites are found.
  - Validates the selected site handle against Statamic's sites or the configuration file.
  - Supports Laravel Prompts for an enhanced user experience on compatible systems (macOS, Linux, WSL), with a fallback to Symfony Console Questions on unsupported platforms.
- **Methods**:
  - `select()`: Manages the site selection process, offering a list of sites or a text input as needed.
  - `validate()`: Checks if the selected site exists in Statamic or the configuration file.

### ContentTypeSelector

- **Purpose**: Manages the selection of a WordPress content type to import from the parsed XML data.
- **File Path**: `src/Services/WordPressImport/Prompts/ContentTypeSelector.php`
- **Key Functionality**:
  - Displays a list of supported content types (e.g., `page`, `post`, `uamspeople`) with their respective counts from the XML file.
  - Ensures only supported content types are available for selection.
  - Exits the process with an error if no supported content types are found in the XML.
  - Utilizes Laravel Prompts on supported systems for a modern interface, with a fallback to Symfony Console Questions.
- **Methods**:
  - `select()`: Prompts the user to choose a content type from the available options.

## Asset Classes

The `src/Services/WordPressImport/Assets` directory contains several classes that handle the import of assets from WordPress into Statamic. These classes work together to manage the downloading, metadata setting, and overwrite behavior of assets during the import process. Storage paths for asset containers (e.g., public vs. private, and subpaths such as `public/assets/*` and `app/private/assets/*`) are defined in `config/filesystems.php`.

### AssetImporter

- **Purpose**: Orchestrates the import of assets from WordPress into Statamic containers.
- **File Path**: `src/Services/WordPressImport/Assets/AssetImporter.php`
- **Key Functionality**:
  - Manages configuration, caching, and delegates downloading, metadata setting, and overwrite behavior to specialized classes.
  - Provides high-level import methods for images and files with caching to prevent redundant downloads.
  - Handles the import of images and files, including thumbnails, and manages the caching of imported file paths.
  - Generates unique filenames for assets to prevent conflicts when multiple files with the same name exist in different WordPress upload directories.
  - Maintains existing caching strategies (attachment IDs for images, full URLs for files) while using unique filename generation for asset paths.
  - **Filters assets based on the WordPress site's base blog URL, skipping assets that are not on the same site to prevent cross-site asset imports.**
  - **Always uses the actual sanitized asset path as returned by Statamic after upload for all field values and references, ensuring that any character replacements (such as en dash to dash) or other Statamic-specific filename changes are honored.**
- **Methods**:
  - `__construct(?string $baseBlogUrl = null)`: Initializes the importer with configuration and dependencies, optionally accepting the base blog URL for site-specific filtering.
  - `clearFileCache()`: Clears the file and image caches for testing or new import runs.
  - `setAssetOverwriteBehavior()`: Configures how to handle existing assets during import.
  - `setBaseBlogUrl(?string $baseBlogUrl)`: Sets the base blog URL for site-specific asset filtering.
  - `importImage()`: Imports an image from WordPress into Statamic, handling metadata and caching. Uses unique filename generation to prevent conflicts. Skips assets not on the same site as the base blog URL.
  - `importThumbnail()`: Imports a thumbnail image with metadata and caching. Uses unique filename generation to prevent conflicts. Skips assets not on the same site as the base blog URL.
  - `importFile()`: Imports a file from WordPress into Statamic, handling caching and container selection. Uses unique filename generation to prevent conflicts. Skips assets not on the same site as the base blog URL. Accepts optional extra metadata merged in by `MetadataSetter::setFileMetadata()` (used for audio post imports via `AudioPostAssetMetadata::defaults()`).

### AssetDownloader

- **Purpose**: Handles downloading and storing assets from URLs into Statamic containers.
- **File Path**: `src/Services/WordPressImport/Assets/AssetDownloader.php`
- **Key Functionality**:
  - Downloads assets from URLs and stores them in Statamic containers.
  - Streams asset content via HTTP to a temporary file, then uploads it to the specified Statamic container.
  - Extracts metadata for audio/video assets using getID3 and sets duration.
  - Logs progress and errors via the console output and ensures temporary resources are cleaned up immediately after use.
  - **Returns the actual final asset path as created by Statamic after upload, which may differ from the original filename due to Statamic's filename sanitization (e.g., en dash to dash, lowercasing, etc).**
- **Methods**:
  - `__construct()`: Initializes the downloader, ensuring getID3 is available.
  - `download()`: Downloads an asset from a URL and stores it in a Statamic container. **Returns the actual sanitized asset path as created by Statamic.**

### MetadataSetter

- **Purpose**: Sets metadata (alt text, caption, exempt_from_alt, file fields) for Statamic assets and merges extra keys onto existing assets without clearing prior data.
- **File Path**: `src/Services/WordPressImport/Assets/MetadataSetter.php`
- **Key Functionality**:
  - Sets metadata for images, including alt text and caption.
  - Sets metadata for files (`original_wordpress_url`, `title`, and optional merged fields).
  - Merges additional keys into existing asset data without removing unrelated fields.
  - Ensures that the metadata is correctly associated with the imported assets.
- **Methods**:
  - `setImageMetadata()`: Sets metadata for an image asset, including alt text and caption.
  - `setFileMetadata()`: Sets file metadata; optional `additionalMetadata` is merged after URL and title.

### AudioPostAssetMetadata

- **Purpose**: Supplies default Statamic asset data for MP3s imported through audio-format posts (`[audio]` shortcode and audio series paths).
- **File Path**: `src/Services/WordPressImport/Assets/AudioPostAssetMetadata.php`
- **Key Functionality**:
  - `defaults()` returns language, creator organization, country/geography, and digital source preset fields applied on successful download into the audio container.
- **Methods**:
  - `defaults()`: Returns the array passed into `AssetImporter::importFile()` / `OverwriteHandler::handleFileImport()` for audio post imports.

### OverwriteHandler

- **Purpose**: Manages overwrite behavior for asset imports.
- **File Path**: `src/Services/WordPressImport/Assets/OverwriteHandler.php`
- **Key Functionality**:
  - Handles the overwrite behavior for asset imports, allowing users to choose between overwriting, skipping, or creating additional files for matching assets.
  - Manages the logic for handling existing assets during the import process.
  - **Uses the actual sanitized asset path as returned by the AssetDownloader for all field values and references, ensuring that any Statamic-specific filename changes are always honored.**
- **Methods**:
  - `setBehavior()`: Configures the overwrite behavior for asset imports.
  - `handleImageImport()`: Handles image import with configured overwrite behavior. **Returns the actual sanitized asset path as created by Statamic.**
  - `handleFileImport()`: Handles file import with configured overwrite behavior. **Returns the actual sanitized asset path as created by Statamic.** Accepts optional extra file metadata merged by `MetadataSetter`.

## Audio Processing Classes

The `src/Services/WordPressImport/Utilities/Processors` directory contains classes that handle the processing of audio content from WordPress, including URL substitution for legacy UAMS domains and audio shortcode processing.

### AudioShortcodeProcessor

- **Purpose**: Processes WordPress [audio] shortcodes and returns audio data with legacy URL substitution.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/AudioShortcodeProcessor.php`
- **Key Functionality**:
  - Parses WordPress [audio] shortcode attributes and extracts MP3 URLs.
  - **Applies automatic URL substitution for legacy UAMS domains and paths to ensure audio files can be properly imported from the current news.uams.edu domain.**
  - Handles both regular audio imports and audio series imports with custom naming and directory structures.
  - After a successful import, applies `AudioPostAssetMetadata::defaults()` to the audio container asset (language, geography, digital source preset, etc.).
  - Returns audio data including imported file paths and placeholder fields for future audio attributes.
  - **Legacy URL Substitution**: Automatically converts legacy UAMS URLs to the current domain:
    - `http://uamshealth.com/wp-content/uploads/` → `https://news.uams.edu/wp-content/uploads/`
    - `https://uamshealth.com/wp-content/uploads/` → `https://news.uams.edu/wp-content/uploads/`
    - `http://news.uamsweb.com/wp-content/uploads/` → `https://news.uams.edu/wp-content/uploads/`
    - `https://news.uamsweb.com/wp-content/uploads/` → `https://news.uams.edu/wp-content/uploads/`
    - `/wp-content/uploads/` → `https://news.uams.edu/wp-content/uploads/`
    - Various legacy audio path patterns (e.g., `/pload/media/`, `/upload/media/`) → `https://news.uams.edu/wp-content/uploads/`
- **Methods**:
  - `parse()`: Parses a WordPress [audio] shortcode, applies URL substitutions, and returns audio data with imported file paths.
  - `substituteLegacyUrls()`: **Protected method that handles URL substitution for legacy UAMS domains and paths to ensure audio files can be properly imported.**

### PostAudioProcessor

- **Purpose**: Processes WordPress audio format content for post collection entries.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/PostAudioProcessor.php`
- **Key Functionality**:
  - Handles specialized processing of audio format posts, including parsing content structured with `<h3>` headings and shortcodes.
  - Extracts audio segments from `<h3>` to `[/accordion]` boundaries.
  - Processes audio files and transcripts from accordion content using `AudioShortcodeProcessor`.
  - Extracts intro text between `<a>` and `[accordion]` tags in each segment.
  - Creates segments and intro fields for the audio blueprint.
  - **Benefits from automatic URL substitution in AudioShortcodeProcessor for legacy UAMS audio URLs.**
- **Methods**:
  - `processAudioFormatContent()`: Parses content to extract segments and creates `segments` with title, file, and transcript fields.
  - `processAudioSegment()`: Processes individual audio segments with title extraction, audio file processing, and transcript extraction.
  - `extractAudioFile()`: Extracts and imports audio files from [audio] shortcodes using AudioShortcodeProcessor.

### AssetUtils

- **Purpose**: Provides utility functions for asset importing.
- **File Path**: `src/Services/WordPressImport/Assets/AssetUtils.php`
- **Key Functionality**:
  - Checks if a file exists at a given URL.
  - Guesses the MIME type of a file based on its extension.
  - Extracts WordPress upload path information from URLs.
  - Generates unique filenames to prevent conflicts between assets with the same name from different WordPress upload directories.
  - **Checks if a URL is on the same site as the base blog URL for site-specific asset filtering.**
  - **Applies lowercase conversion** to generated filenames when `config('statamic.assets.lowercase')` is enabled, ensuring consistency with Statamic's asset naming conventions.
- **Methods**:
  - `fileExists()`: Checks if a file exists at a given URL using HTTP HEAD request.
  - `guessMimeType()`: Determines the MIME type of a file based on its extension.
  - `isSameSiteUrl(string $url, string $baseBlogUrl)`: Checks if a URL is on the same site as the base blog URL by comparing normalized domain paths.
  - `extractWordPressPathInfo()`: Extracts year/month information from WordPress URLs and generates unique filenames with year-month prefixes. Supports both multisite (/sites/{id}/{year}/{month}/) and regular WordPress upload patterns (/wp-content/uploads/{year}/{month}/). Falls back to current date for URLs without year/month patterns. **Applies lowercase conversion when configured in Statamic.**
  - `generateUniqueFilename()`: Creates unique filenames with year-month prefixes to prevent conflicts when multiple files with the same name exist in different WordPress upload directories. **Applies lowercase conversion when configured in Statamic.**

## Globals Classes

The `src/Services/WordPressImport/Globals` directory contains classes that handle the creation and modification of Statamic global sets from WordPress content. These classes work together to ensure proper mapping and organization of global content during the import process.

### BaseGlobalCreator

- **Purpose**: Provides common functionality for creating or modifying Statamic global sets from WordPress content.
- **File Path**: `src/Services/WordPressImport/Globals/BaseGlobalCreator.php`
- **Key Functionality**:
  - Manages global set creation with support for custom field mapping.
  - Handles overwrite behavior configuration.
  - Provides abstract methods for type-specific implementations.
  - Ensures adherence to DRY and SRP principles.
- **Methods**:
  - `createGlobals()`: Abstract method for creating global sets (implemented by subclasses).
  - `setGlobalOverwriteBehavior()`: Configures how to handle existing global sets.

### GlobalCreator

- **Purpose**: Orchestrates the global set creation process and manages dependencies.
- **File Path**: `src/Services/WordPressImport/Globals/GlobalCreator.php`
- **Key Functionality**:
  - Coordinates the creation of global sets across different content types.
  - Manages dependencies between global creation and metadata mapping.
  - Delegates type-specific global set modifications to dedicated creator classes.
  - Reports unaddressed XML keys for debugging.
- **Methods**:
  - `createGlobals()`: Main entry point for creating global sets.
  - `setGlobalOverwriteBehavior()`: Configures how to handle existing global sets.
  - `getCreator()`: Returns the appropriate creator for a content type.

### Creator Classes

The following classes in `src/Services/WordPressImport/Globals/Creators` extend `BaseGlobalCreator` to handle specific global content types:

#### RedirectRuleGlobalCreator

- **Purpose**: Modifies the Statamic 'redirects' global set with WordPress 'redirect_rule' content.
- **File Path**: `src/Services/WordPressImport/Globals/Creators/RedirectRuleGlobalCreator.php`
- **Key Functionality**:
  - Maps WordPress redirect rules to Statamic global set fields.
  - Handles redirect-specific fields and metadata.
  - Manages overwrite behavior for existing redirect rules.
  - Appends new redirects to the global set's replicator field.
  - For a vanity domain listed in `wordpress-import.redirect_domains.hosts`, writes one `redirect_domains` entry per published rule instead (wordpress-importer-archive#1306), keeping the rule WordPress serves when several share a source (#108). See *Vanity redirect domains* below.
- **Methods**:
  - `createGlobals()`: Implements the creation of redirect rules in the global set, or routes a vanity domain's rules to the collection.
  - `redirectDomainHost()`: The listed vanity domain an export's `base_blog_url` belongs to, if any.

## Entry Classes

The `src/Services/WordPressImport/Entries` directory contains several classes that handle the creation and management of Statamic entries from WordPress content. These classes work together to ensure proper content mapping, validation, and organization during the import process.

### BaseEntryCreator

- **Purpose**: Provides common functionality for creating Statamic entries from WordPress content.
- **File Path**: `src/Services/WordPressImport/Entries/BaseEntryCreator.php`
- **Key Functionality**:
  - Manages entry creation with support for custom field mapping and content processing.
  - Handles slug and ID generation, including date prefixing for scheduled content.
  - Manages entry saving with configurable overwrite behaviors.
  - Maintains page data for tree building.
  - Provides utility methods for content validation and status mapping.
- **Methods**:
  - `createEntries()`: Abstract method for creating entries (implemented by subclasses).
  - `mapStatusAndVisibility()`: Maps WordPress status and visibility fields to Statamic fields.
  - `generateSlugAndId()`: Creates unique slugs and IDs for entries.
  - `saveEntry()`: Handles entry saving with overwrite behavior options.
  - `addPageData()`: Stores metadata for tree building.
  - `isEmptyContent()`: Validates content emptiness.

### EntryCreator

- **Purpose**: Orchestrates the entry creation process and manages dependencies.
- **File Path**: `src/Services/WordPressImport/Entries/EntryCreator.php`
- **Key Functionality**:
  - Coordinates the creation of entries across different content types.
  - Manages dependencies between entry creation, taxonomy validation, and tree building.
  - Handles Classic block detection and note addition.
  - Handles Row block detection and note addition.
  - Handles Details block detection and note addition.
  - Handles Freeform block detection and note addition.
  - Manages TablePress content import.
  - Provides access to collected data for other services.
- **Methods**:
  - `createEntries()`: Main entry point for creating entries.
  - `setEntryOverwriteBehavior()`: Configures how to handle existing entries.
  - `setTableOverwriteBehavior()`: Configures TablePress content handling.
  - `hasClassicBlock()`: Checks for Classic block content.
  - `hasRowBlock()`: Checks for Row block content.
  - `hasDetailsBlock()`: Checks for Details block content.
  - `hasFreeformBlock()`: Checks for Freeform block content.
  - `importTables()`: Handles TablePress content import.
  - `getCreator()`: Returns the appropriate creator for a content type.

### SiteConfigurator

- **Purpose**: Manages site-specific configuration for collections and taxonomies.
- **File Path**: `src/Services/WordPressImport/Entries/SiteConfigurator.php`
- **Key Functionality**:
  - Enables collections and taxonomies for specific sites.
  - Updates YAML configuration files for site enabling.
  - Creates necessary tree files for collections.
  - Handles cache clearing after configuration changes.
- **Methods**:
  - `enableForSite()`: Main method for enabling site configuration.
  - `enableCollection()`: Enables a collection for a site.
  - `enableTaxonomy()`: Enables a taxonomy for a site.

### TaxonomyValidator

- **Purpose**: Validates and manages taxonomy terms during import.
- **File Path**: `src/Services/WordPressImport/Entries/TaxonomyValidator.php`
- **Key Functionality**:
  - Validates taxonomy terms for different content types.
  - Handles term hierarchy and slug generation.
  - Manages term relationships and parent-child connections.
  - Ensures proper term organization in Statamic.
- **Methods**:
  - `validatePeopleGroupTerms()`: Validates people group taxonomy terms.
  - `validatePostCategoryTerms()`: Validates post category terms.
  - `validateLogosItemCategoriesTerms()`: Validates logo item categories.
  - `validateRegionTerms()`: Validates region taxonomy terms.
  - `validateLocationTypeTerms()`: Validates location type terms.
  - `buildTermMap()`: Creates a map of term relationships.
  - `getFullTermSlug()`: Generates complete term slugs.

### Creator Classes

The following classes in `src/Services/WordPressImport/Entries/Creators` extend `BaseEntryCreator` to handle specific content types:

#### PageEntryCreator

- **Purpose**: Creates Statamic entries for WordPress pages.
- **File Path**: `src/Services/WordPressImport/Entries/Creators/PageEntryCreator.php`
- **Key Functionality**:
  - Maps WordPress page content to Statamic entries.
  - Handles page-specific fields and metadata.
  - Manages parent-child relationships for tree building.
  - Processes page content and shortcodes.
  - Uses streaming parser for memory-efficient processing of attachments and terms.
  - Handles ACF blocks, Classic content, Row content, Details content, and Freeform content with proper error handling.
- **Methods**:
  - `__construct()`: Initializes the creator with parent EntryCreator for shared methods.
  - `createEntries()`: Creates Statamic entries from WordPress pages, handling ACF blocks, Classic content, Row content, Details content, and Freeform content using streaming parser.
  - `hasShowPeople()`: Indicates if content contains show-people shortcodes.
  - `getPostCategories()`: Returns empty array as pages don't use post categories.

#### PeopleEntryCreator

- **Purpose**: Creates Statamic entries for WordPress people content.
- **File Path**: `src/Services/WordPressImport/Entries/Creators/PeopleEntryCreator.php`
- **Key Functionality**:
  - Maps WordPress people content to Statamic entries.
  - Handles people-specific fields and metadata.
  - Manages people group taxonomy relationships.
  - Processes people content and shortcodes.
  - Uses streaming parser for memory-efficient processing of attachments and terms.
- **Methods**:
  - `buildTermMap()`: Builds a map of term slugs to their full slugs and parent slugs.
  - `getFullTermSlug()`: Constructs the full slug path for a term, recursively prepending ancestor slugs.
  - `createEntries()`: Creates Statamic entries from WordPress people content, handling taxonomy terms and metadata using streaming parser.
  - `hasShowPeople()`: Returns false as people entries don't use show-people shortcodes.
  - `getPostCategories()`: Returns empty array as people entries use people_group taxonomy.
  - `getPeopleGroups()`: Returns the people groups used in parsed entries.

#### PostEntryCreator

- **Purpose**: Creates Statamic entries for WordPress posts.
- **File Path**: `src/Services/WordPressImport/Entries/Creators/PostEntryCreator.php`
- **Key Functionality**:
  - Maps WordPress post content to Statamic entries.
  - Handles post-specific fields and metadata.
  - Manages post categories and tags.
  - Processes post content and shortcodes.
  - Uses streaming parser for memory-efficient processing of attachments and terms.
  - Switches the blueprint to `news_release` when a post carries a non-empty `news_release_pdf_url`. It does **not** download the PDF: the host app regenerates it from its own template on save, owns the `news_release_pdf` field, and deletes any other asset tagged for the entry (wordpress-importer-archive#620).
- **Methods**:
  - `createEntries()`: Creates Statamic entries from WordPress posts, handling categories and metadata using streaming parser.
  - `hasShowPeople()`: Returns false as posts don't use show-people shortcodes.
  - `getPostCategories()`: Returns the post categories used in parsed entries.

#### TablePressEntryCreator

- **Purpose**: Creates Statamic entries for WordPress TablePress content.
- **File Path**: `src/Services/WordPressImport/Entries/Creators/TablePressEntryCreator.php`
- **Key Functionality**:
  - Maps WordPress TablePress content to Statamic entries.
  - Handles table-specific fields and metadata.
  - Manages table data and formatting.
  - Processes table content and shortcodes.
- **Methods**:
  - `setOverwriteBehavior()`: Configures how to handle existing table entries.
  - `createEntries()`: Creates Statamic entries from WordPress TablePress content.
  - `generateSlugAndId()`: Generates unique slugs and IDs for table entries.
  - `saveEntry()`: Saves table entries with overwrite behavior handling.
  - `generateUniqueId()`: Generates unique IDs for table entries.
  - `hasShowPeople()`: Returns false as tables don't use show-people shortcodes.
  - `getPostCategories()`: Returns empty array as tables don't use post categories.

#### KbEntryCreator

- **Purpose**: Creates Statamic entries for WordPress MinervaKB content.
- **File Path**: `src/Services/WordPressImport/Entries/Creators/KbEntryCreator.php`
- **Key Functionality**:
  - Maps WordPress MinervaKB content to Statamic entries.
  - Handles knowledge base-specific fields and metadata.
  - Manages hierarchical structure of knowledge base content.
  - Processes knowledge base content and shortcodes.
  - Uses MinervaKBProcessor for specialized processing logic.
- **Methods**:
  - `createEntries()`: Creates Statamic entries from WordPress MinervaKB content using MinervaKBProcessor.
  - `hasShowPeople()`: Returns false as knowledge base entries don't use show-people shortcodes.
  - `getPostCategories()`: Returns empty array as knowledge base entries don't use post categories.

## Structure Classes

The `src/Services/WordPressImport/Structures` directory contains classes that handle the organization and structure of imported content in Statamic. These classes are responsible for creating and managing hierarchical relationships between content items, particularly for collections that support tree structures.

### TreeBuilder

- **Purpose**: Builds and saves the nested tree structure for Statamic collections.
- **File Path**: `src/Services/WordPressImport/Structures/TreeBuilder.php`
- **Key Functionality**:
  - Constructs a hierarchical tree from WordPress page data based on parent-child relationships and menu order.
  - Identifies and sets the front page as the root of the tree structure.
  - Sorts pages by menu order and title within each level of the hierarchy.
  - Handles missing parent pages by placing them at the top level.
  - Creates and updates collection tree structures for multi-site organization.
  - Maintains a cache of processed collections to prevent duplicate tree building.
- **Methods**:
  - `buildAndSaveTree()`: Orchestrates the tree building and saving process for a collection.
  - `buildTree()`: Creates the hierarchical structure from page data.
  - `assignChildrenRecursively()`: Recursively assigns children to parent nodes.
  - `sortTreeLevel()`: Sorts nodes within a tree level by menu order and title.
  - `cleanNode()`: Simplifies node structure to include only essential data.
  - `resetProcessedCollections()`: Clears the cache of processed collections.

## Migration API Classes

The `src/Services/WordPressImport/MigrationApi` directory contains the client for the UAMS WordPress migration API — the read path for content that a WXR export cannot carry (ACF options pages, per-site plugin state). It is the counterpart to the parser classes above: those read a file, this reads a live site.

The contract it implements is documented in `UAMS-Web/uamswp-migration-api`, not inferred here: REST namespace `uamswp-migration/v1`, a shared secret in the `X-UAMSWP-Migration-Key` header, `?page=` / `?per_page=` bounded at 200 with `X-WP-Total` and `X-WP-TotalPages` on the response, and `?site=` selecting the network site.

### Client

- **Purpose**: Reads the migration API, failing loudly rather than returning an empty result.
- **File Path**: `src/Services/WordPressImport/MigrationApi/Client.php`
- **Key Functionality**:
  - Builds requests against the `uamswp-migration/v1` namespace, presenting the shared secret in the `X-UAMSWP-Migration-Key` header.
  - **Addresses a site through that site's own REST root**, resolved from the `link` each entry of `GET /sites` carries — not through `?site=`. Measured against a live multisite (wordpress-importer-archive#671): `post-types` and `taxonomies` **refuse** `?site=` with `400 uamswp_migration_api_registration_is_not_site_scoped`, because they derive from `post_type_exists()` and registration is process-global, so they can only answer for the site that booted the request. For data endpoints the two forms are equivalent — `posts/post?site=2` and site 2's own root both returned 1 record against site 1's 46, and `options` returned 235 rows either way against site 1's 1206 — so the per-site root is used uniformly rather than branching per endpoint.
  - Pages through a collection endpoint until the records seen reach the `X-WP-Total` the API reported.
  - **Raises on every failure mode** — an unreachable endpoint, a non-2xx, a body that is not JSON, and a short read — rather than handing back an empty or partial set. A 200 carrying an HTML login page is the most plausible disguise for the silent-empty failure, and it is rejected as a malformed body.
  - Preserves the API's ID-ascending ordering rather than re-sorting, because that ordering is what stops a record moving between pages while a live site is edited mid-run.
  - Clamps the requested page size to the API's maximum of 200, and floors it at 1, so the client's own arithmetic cannot disagree with what the server serves.
- **Methods**:
  - `forRun()`: Builds a client from the network address and API key an operator pastes when the run starts. **Neither is stored anywhere** — the estate is 26 networks each issuing its own key, so configuration would hold 26 live credentials for a job that reads one network at a time (wordpress-importer-archive#982). Refuses an address that is not an absolute `https://` URL carrying a host, and refuses an empty key rather than sending an unauthenticated request the API would answer `401`. The `https` check is here rather than at request time because `withBaseUrl()` pins later hops to the scheme the run began on. Page size still comes from host configuration, and an unusable one still raises.
  - `withBaseUrl()`: Returns a client for the same API at another site's REST root, carrying the same credential and page size.
  - `get()`: Fetches a single page from an endpoint.
  - `paginate()`: Fetches every page of a collection endpoint.

### Loader (MinervaKB settings)

- **Purpose**: Loads the MinervaKB plugin settings, from the migration API or from a hand-made export file.
- **File Path**: `src/Services/WordPressImport/Prompts/MinervaKbSettings/Loader.php`
- **Key Functionality**:
  - Reads the `minerva-kb-options` row from `GET /options` when `setMigrationApiSource()` has been called, and the export file otherwise. The file path argument is ignored entirely on the API path — the row is the source of truth there, with no fallback.
  - `MKB_Options` persists the **whole settings set as a single `wp_options` row**, stored as a JSON string rather than PHP-serialized, and the endpoint serves it as the string it is. So the value arrives in the same shape the export file carries, and `Validator`, `Extractor`, `Selector` and `Prompter` are unchanged.
  - **Raises on an absent row rather than returning empty settings.** The row can be missing because MinervaKB is not installed on that site *or* because the options endpoint withheld it — its secret rule withholds a whole row whose contents match, and it reports how many rows it withheld but never which. Both readings are named in the error. Whether a real network's blob survives that filter is issue wordpress-importer-archive#665.
- **Methods**:
  - `setMigrationApiSource()`: Points this run at the migration API. Called by the import command when `--source=api`; the loader is a container singleton so the command and `Selector` share one instance.
  - `loadSettings()`: Reads and parses from whichever source is configured.

### MigrationApiException

- **Purpose**: The single error type every migration API failure surfaces as.
- **File Path**: `src/Services/WordPressImport/MigrationApi/MigrationApiException.php`
- **Key Functionality**:
  - Extends `RuntimeException`, carrying the underlying transport failure as the previous exception so a DNS failure and a TLS failure remain distinguishable.

## General Utility Classes

The `src/Services/WordPressImport/Utilities` directory contains classes that provide core functionality for processing and transforming WordPress content into Statamic format. These classes handle text processing, content parsing, metadata mapping, and various utility functions essential for the import process.

### BardParser

- **Purpose**: Parses HTML content into Statamic Bard format using Tiptap.
- **File Path**: `src/Services/WordPressImport/Utilities/BardParser.php`
- **Key Functionality**:
  - Converts raw HTML to ProseMirror JSON for Statamic Bard fields.
  - Processes inline formatting and block elements.
  - Handles WordPress block comments and UTF-8 encoding.
- **Methods**:
  - `parse()`: Converts HTML to Bard-compatible ProseMirror JSON.

### ContentProcessor

- **Purpose**: Orchestrates the processing of WordPress content into Statamic format.
- **File Path**: `src/Services/WordPressImport/Utilities/ContentProcessor.php`
- **Key Functionality**:
  - Coordinates content processing between different components.
  - Handles ACF blocks and non-ACF content with improved mixed content support.
  - Manages table entry linking and content transformation.
  - **Static variable reset**: Resets static variables at the beginning of processing each page to prevent cross-contamination.
  - **Enhanced mixed content handling**: Properly processes pages containing both ACF blocks and standard WordPress content by making a final call to `PageBuilderParser::parseNode` to trigger finalization logic.
  - **Robust finalization**: Ensures accumulated content is properly finalized and added to the page_builder array, even when the final node is not processed by ACF or non-ACF parsers.
- **Methods**:
  - `process()`: Main entry point for content processing, with enhanced mixed content support and static variable reset.
  - `linkTableEntries()`: Links table entries in content.
  - `hasAcfBlocks()`: Detects ACF blocks in content.

### FieldMapper

- **Purpose**: Maps WordPress fields to Statamic field types.
- **File Path**: `src/Services/WordPressImport/Utilities/FieldMapper.php`
- **Key Functionality**:
  - Handles field type conversion and mapping.
  - Manages field relationships and dependencies.
  - Ensures proper data structure for Statamic.
  - Sets `hide_from_menu` from `page_hide_from_menu` (value `1`) or from `parent` meta
    (value `on`, WordPress “parent” placeholder page).
  - Sets `hide_breadcrumbs` from `page_hide_breadcrumbs` (value `1`) or from `breadcrumb`
    meta (value `on`).
- **Methods**:
  - `mapFields()`: Maps WordPress metadata to Statamic fields, handling direct mappings and complex transformations.
  - `mapTitleOptions()`: Maps WordPress page_title_options values to Statamic title_options values.

### HeadingDemoter

- **Purpose**: Adjusts heading levels in content to maintain hierarchy.
- **File Path**: `src/Services/WordPressImport/Utilities/HeadingDemoter.php`
- **Key Functionality**:
  - Demotes heading levels to maintain proper document structure.
  - Preserves heading hierarchy in nested content.
- **Methods**:
  - `demote()`: Demotes headings in a Bard content array, incrementing levels and converting h6 to bold paragraphs.

### ImageProcessor

- **Purpose**: Coordinates image processing into Statamic Bard image sets.
- **File Path**: `src/Services/WordPressImport/Utilities/ImageProcessor.php`
- **Key Functionality**:
  - Processes images and wp:image blocks.
  - Manages image processing pipeline.
  - Handles image metadata and attributes.
- **Methods**:
  - `processImage()`: Processes images into Bard sets.
  - `addProcessor()`: Adds custom processors to the pipeline.

### KeyTracker

- **Purpose**: Tracks WordPress metadata keys during import.
- **File Path**: `src/Services/WordPressImport/Utilities/KeyTracker.php`
- **Key Functionality**:
  - Maintains lists of ignored and mapped keys.
  - Reports unaddressed keys for debugging.
  - Tracks metadata mapping progress.
- **Methods**:
  - `trackKeys()`: Tracks metadata keys during import.
  - `getUnaddressedKeys()`: Retrieves unaddressed keys.

### LinkHelper

- **Purpose**: Provides utility functions for link processing.
- **File Path**: `src/Services/WordPressImport/Utilities/LinkHelper.php`
- **Key Functionality**:
  - Handles link URL processing and validation.
  - Manages link attributes and relationships.
- **Methods**:
  - `getUrl()`: Extracts the URL from a link value, handling JSON arrays or serialized strings.
  - `getTarget()`: Extracts the target from a link value, returning `true` for new tab or `false` otherwise.

### LinkProcessor

- **Purpose**: Processes links in WordPress content.
- **File Path**: `src/Services/WordPressImport/Utilities/LinkProcessor.php`
- **Key Functionality**:
  - Converts WordPress links to Statamic format.
  - Handles internal and external links.
  - Manages link attributes and relationships.
- **Methods**:
  - `process()`: Processes HTML to import linked files and update href attributes, handling PDF, DOCX, XLSX, and PPTX files.

### MetadataMapper

- **Purpose**: Coordinates metadata mapping to Statamic fields.
- **File Path**: `src/Services/WordPressImport/Utilities/MetadataMapper.php`
- **Key Functionality**:
  - Orchestrates metadata parsing and mapping.
  - Manages field relationships and dependencies.
  - Tracks mapping progress and unaddressed keys.
- **Methods**:
  - `mapMetadata()`: Maps WordPress metadata to Statamic fields.
  - `getUnaddressedKeys()`: Retrieves unaddressed keys.

### MetaDataParser

- **Purpose**: Parses WordPress postmeta data.
- **File Path**: `src/Services/WordPressImport/Utilities/MetaDataParser.php`
- **Key Functionality**:
  - Extracts metadata from WordPress XML.
  - Processes postmeta nodes and values.
  - Handles metadata relationships.
- **Methods**:
  - `parse()`: Parses WordPress postmeta nodes into an associative array of meta keys and values.

### PasswordVerifier

- **Purpose**: Verifies and processes WordPress passwords.
- **File Path**: `src/Services/WordPressImport/Utilities/PasswordVerifier.php`
- **Key Functionality**:
  - Validates WordPress password hashes.
  - Handles password migration to Statamic.
- **Methods**:
  - `verify()`: Verifies a user-entered password against the entry's stored password, supporting both hashed and plain-text passwords.

### SiteResolver

- **Purpose**: Resolves site handles during import.
- **File Path**: `src/Services/WordPressImport/Utilities/SiteResolver.php`
- **Key Functionality**:
  - Manages site selection and validation.
  - Handles multi-site configuration.
  - Provides site context for content processing.
- **Methods**:
  - `getCurrentSite()`: Retrieves current site handle.
  - `setSelectedSite()`: Sets selected site.
  - `clearSelectedSite()`: Clears site selection.

### SlugGenerator

- **Purpose**: Generates unique slugs with hierarchical conflict resolution for entries.
- **File Path**: `src/Services/WordPressImport/Utilities/SlugGenerator.php`
- **Key Functionality**:
  - Consolidates slug generation logic from various entry creators.
  - Provides hierarchical slug conflict resolution by checking if slugs conflict at the same parent level.
  - **Uses `.` as separator for slug conflicts** (e.g., `about-us.1`, `about-us.2`) to match Statamic's default behavior.
  - Eliminates the need for post-import renaming since generated slugs are already in the correct format.
  - Handles both structured collections (with hierarchy) and flat taxonomies appropriately.
- **Methods**:
  - `generateSlugAndId()`: Generates unique slugs and IDs for entries with hierarchical conflict checking.
  - `generateTableSlugAndId()`: Generates unique slugs and IDs for table entries with specific format requirements.

### AcfFieldNormalizer

- **Purpose**: Normalizes ACF field names by mapping field-based variations to standard names.
- **File Path**: `src/Services/WordPressImport/Utilities/AcfFieldNormalizer.php`
- **Key Functionality**:
  - Handles the conversion of WordPress ACF field names like `field_side_heading_b` to standard names like `side_heading`.
  - Provides a generic interface for normalizing ACF field names across different block parsers.
  - Allows each parser to define its own field mappings while using consistent normalization logic.
  - Preserves indexed fields for repeater processing in normalized data.
  - Handles nested field structures for complex ACF blocks.
- **Methods**:
  - `normalize()`: Normalizes field names by mapping field-based variations to standard names and handling nested structures.
  - `createFieldMappings()`: Creates field mappings for a given prefix pattern, useful for creating mappings for other ACF block types.

### TextProcessor

- **Purpose**: Processes text content for Statamic Bard fields.
- **File Path**: `src/Services/WordPressImport/Utilities/TextProcessor.php`
- **Key Functionality**:
  - Handles text manipulation and DOM processing.
  - Manages Bard field content transformation.
  - Processes shortcodes and formatting.
  - **Processes WordPress block comments** (e.g., `<!-- wp:table -->`, `<!-- wp:image -->`, `<!-- wp:shortcode -->`) using a configurable set of block processors, ensuring robust import of tables, images, files, quotes, and other block-level content.
  - **Preserves Gutenberg block structures** (e.g., `wp:list` for unordered and ordered lists) by loading HTML as a single unit, preventing issues like premature tag closure during DOM parsing.
  - The set of block processors is defined in `getBlockProcessors()`, which by default includes:
    - [`ShortcodeBlockProcessor`](#shortcodeblockprocessor): Handles `wp:shortcode` blocks.
    - [`FileBlockProcessor`](#fileblockprocessor): Handles `wp:file` blocks.
    - [`EmbedProcessor`](#embedprocessor): Handles `wp:embed` blocks.
    - [`QuoteProcessor`](#quoteprocessor): Handles `wp:quote` blocks.
    - [`TableProcessor`](#tableprocessor): Handles `wp:table` blocks.
    - [`WpImageBlockProcessor`](#wpimageblockprocessor): Handles `wp:image` blocks.
    - [`TablePressBlockProcessor`](#tablepressblockprocessor): Handles `wp:tablepress/table` blocks.
    - [`GravityFormsBlockProcessor`](#gravityformsblockprocessor): Handles `wp:gravityforms/form` blocks.
    - [`FormidableFormsBlockProcessor`](#formidableformsblockprocessor): Handles `wp:formidable/simple-form` blocks.
    - [`NinjaFormsBlockProcessor`](#ninjaformsblockprocessor): Handles `wp:ninja-forms/form` blocks.
  - **Note**: `wp:list` blocks are processed natively by `BardParser` using its `BulletList`, `OrderedList`, and `ListItem` extensions, eliminating the need for a dedicated block processor.
  - This extensible pipeline ensures that all major WordPress block types are converted to Statamic Bard sets or compatible content.

- **Dependencies**:
  - [`AssetImporter`](#asset-classes): Handles asset import and management.
  - [`BardParser`](#bardparser): Converts HTML to Bard-compatible ProseMirror JSON.
  - [`LinkProcessor`](#linkprocessor): Processes and rewrites links in content.
  - [`WpAutoP`](#wpautop): Handles WordPress-style paragraph formatting.
  - [`ShortcodeProcessor`](#shortcodeprocessor): Extracts and processes shortcodes.
  - [`TablePressShortcodeProcessor`](#tablepressshortcodeprocessor): Handles TablePress shortcodes.
  - [`WpImageBlockProcessor`](#wpimageblockprocessor): Handles `wp:image` blocks and delegates to [`ImageProcessor`](#imageprocessor).
  - [`ImageProcessor`](#imageprocessor): Coordinates image processing pipeline for Bard sets.
  - `DOMDocument`, `DOMElement`, `DOMXPath`: Standard PHP DOM classes for HTML parsing.
  - `OutputInterface`: Symfony console output for logging.
  - `Log`: Laravel logging facade.

- **Methods**:
  - `processForBard()`: Processes text for Bard fields, including block and shortcode handling. When processing Classic Editor (non-Gutenberg) content, applies WpAutoP early (before shortcode and link processing) so wpautop structure in `<content:encoded>`—double line breaks as paragraph boundaries—is recognized and converted to `<p>` tags before any step that might alter newlines. When `<content:encoded>` is only a bare `[gravityform id="…"]`, `[formidable id="…"]` or `[ninja_form id="…"]` shortcode (no `wp:shortcode` wrapper), resolves it via the same shortcode-only fast path used for single `wp:shortcode` blocks so Bard content is not dropped.
  - `normalizeUtf8()`: Normalizes UTF-8 encoding.
  - `decodeEntities()`: Decodes HTML entities.

### WpAutoP

- **Purpose**: Converts line breaks to paragraphs in WordPress style. Applied early in the TextProcessor pipeline for Classic Editor content so paragraph boundaries in `<content:encoded>` are preserved.
- **File Path**: `src/Services/WordPressImport/Utilities/WpAutoP.php`
- **Key Functionality**:
  - Processes text to add paragraph tags.
  - Handles line break conversion.
  - Maintains WordPress-style formatting.
- **Methods**:
  - `process()`: Formats text with paragraph and line break tags, replicating WordPress's wpautop() function, with protection for specific HTML tags.

### Processor Classes

The following classes in `src/Services/WordPressImport/Utilities/Processors` handle specific content processing tasks:

#### BlockProcessorInterface

- **Purpose**: Defines interface for block processors.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/BlockProcessorInterface.php`
- **Key Functionality**:
  - Defines contract for block processing.
  - Ensures consistent block handling.
- **Methods**:
  - `canProcess()`: Determines if the processor can handle the given DOM node.
  - `process()`: Processes the DOM node and returns Tiptap-compatible HTML or processing details.

#### CaptionShortcodeProcessor

- **Purpose**: Processes WordPress caption shortcodes.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/CaptionShortcodeProcessor.php`
- **Key Functionality**:
  - Handles image captions.
  - Manages caption formatting.
- **Methods**:
  - `__construct()`: Initializes the processor with an optional ImageProcessor instance.
  - `parse()`: Parses a `[caption]` shortcode into a Statamic image Bard set, extracting image attributes and caption text.

#### EmbedProcessor

- **Purpose**: Processes embedded content.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/EmbedProcessor.php`
- **Key Functionality**:
  - Handles embedded media.
  - Manages embed formatting.
- **Methods**:
  - `canProcess()`: Determines if the processor can handle the given DOM node by checking for wp:embed comments.
  - `process()`: Processes a wp:embed block into Tiptap-compatible HTML, handling video URLs, alignment, and captions.

#### FileBlockProcessor

- **Purpose**: Processes WordPress wp:file blocks into Tiptap-compatible HTML for Statamic Bard sets.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/FileBlockProcessor.php`
- **Key Functionality**:
  - Processes wp:file blocks into Tiptap-compatible HTML.
  - Handles file imports with graceful disabled upload handling.
  - Manages file metadata and DOM processing.
- **Methods**:
  - `canProcess()`: Determines if the processor can handle the given DOM node by checking for wp:file comments.
  - `process()`: Processes a wp:file block into Tiptap-compatible HTML, handling file URLs, titles, and download buttons.

#### FormidableFormsBlockProcessor

- **Purpose**: Processes Formidable Forms content.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/FormidableFormsBlockProcessor.php`
- **Key Functionality**:
  - Handles form content.
  - Manages form structure.
- **Methods**:
  - `canProcess()`: Determines if the processor can handle the given DOM node by checking for wp:formidable/simple-form comments.
  - `process()`: Processes a wp:formidable/simple-form block into Tiptap-compatible HTML, handling form IDs and notes.
  - `getFormIds()`: Returns the form IDs recorded by this processor (shared, via `FormProcessorTrait`).
  - `clearFormIds()`: Clears the recorded form IDs between imports.

#### FormidableFormsShortcodeProcessor

- **Purpose**: Processes Formidable Forms shortcodes.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/FormidableFormsShortcodeProcessor.php`
- **Key Functionality**:
  - Handles form shortcodes.
  - Manages shortcode attributes.
- **Methods**:
  - `parse()`: Parses a WordPress formidable shortcode into a Statamic form Bard set, handling form IDs and notes.
  - `toPageBuilderBlock()`: Builds a page builder block structure for a formidable shortcode, encapsulating all mapping logic.
  - `getFormIds()`: Returns the form IDs recorded by this processor (shared, via `FormProcessorTrait`).
  - `clearFormIds()`: Clears the recorded form IDs between imports.

#### GravityFormsBlockProcessor

- **Purpose**: Processes Gravity Forms content.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/GravityFormsBlockProcessor.php`
- **Key Functionality**:
  - Handles form content.
  - Manages form structure.
- **Methods**:
  - `canProcess()`: Determines if the processor can handle the given DOM node by checking for wp:gravityforms/form comments.
  - `process()`: Processes a wp:gravityforms/form block into Tiptap-compatible HTML, handling form IDs and notes.
  - `getFormIds()`: Returns the form IDs recorded by this processor (shared, via `FormProcessorTrait`).
  - `clearFormIds()`: Clears the recorded form IDs between imports.

#### GravityFormsShortcodeProcessor

- **Purpose**: Processes Gravity Forms shortcodes.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/GravityFormsShortcodeProcessor.php`
- **Key Functionality**:
  - Handles form shortcodes.
  - Manages shortcode attributes.
- **Methods**:
  - `parse()`: Parses a WordPress gravityform shortcode into a Statamic form Bard set, handling form IDs and notes.
  - `toPageBuilderBlock()`: Builds a page builder block structure for a gravityform shortcode, encapsulating all mapping logic.
  - `getFormIds()`: Returns the form IDs recorded by this processor (shared, via `FormProcessorTrait`).
  - `clearFormIds()`: Clears the recorded form IDs between imports.

#### NinjaFormsBlockProcessor

- **Purpose**: Processes Ninja Forms content.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/NinjaFormsBlockProcessor.php`
- **Key Functionality**:
  - Handles form content.
  - Manages form structure.
  - Aliases the block's `formID` attribute (capital D, as Ninja Forms spells it) onto the `formId` the shared trait reads.
- **Methods**:
  - `canProcess()`: Determines if the processor can handle the given DOM node by checking for wp:ninja-forms/form comments, including the self-closing form.
  - `process()`: Processes a wp:ninja-forms/form block into Tiptap-compatible HTML, handling form IDs and notes. Consumes one node (the comment), since the block is self-closing.
  - `handleTitleAndDescription()`: Deliberately a no-op — Ninja Forms blocks carry no display toggle; whether the heading renders is a form setting rather than a block attribute.
  - `getFormIds()`: Returns the form IDs recorded by this processor (shared, via `FormProcessorTrait`).
  - `clearFormIds()`: Clears the recorded form IDs between imports.

#### NinjaFormsShortcodeProcessor

- **Purpose**: Processes Ninja Forms shortcodes.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/NinjaFormsShortcodeProcessor.php`
- **Key Functionality**:
  - Handles form shortcodes.
  - Manages shortcode attributes, accepting both the quoted and bare forms of the id (`[ninja_form id=2]` and `[ninja_form id='2']`).
- **Methods**:
  - `parse()`: Parses a WordPress ninja_form shortcode into a Statamic form Bard set, handling form IDs and notes.
  - `getFormIds()`: Returns the form IDs recorded by this processor (shared, via `FormProcessorTrait`).
  - `clearFormIds()`: Clears the recorded form IDs between imports.
- **Note**: Unlike the Gravity Forms and Formidable Forms shortcode processors this one has no `toPageBuilderBlock()`; it is a thin wrapper over the shared shortcode trait.

#### BlogrollShortcodeProcessor

- **Purpose**: Processes WordPress `[blogroll ...]` shortcodes into Statamic `uamsweb_block_news` page_builder sets.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/BlogrollShortcodeProcessor.php`
- **Key Functionality**:
  - Treats blogroll like wp:rss and ACF blocks for page builder detection (hasPageBuilderBlocks, hasAcfBlocksInContent).
  - Maps shortcode attributes (style, image, date, author, category, category_name) to uamsweb_block_news fields.
  - When a wp:shortcode block contains exactly one [blogroll] shortcode, ShortcodeBlockProcessor returns a page_builder set; NonAcfBlockParser and PageBuilderParser add it as a direct set to orderedSets.
- **Methods**:
  - `parse()`: Returns minimal set for Bard placeholder (primary output is toPageBuilderBlock).
  - `toPageBuilderBlock()`: Builds uamsweb_block_news replicator set with block_heading placeholder, local=true, offset=0, background_color=bg-auto, and mapped hide_* and category fields.

#### PeopleShortcodeProcessor

- **Purpose**: Processes people shortcodes.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/PeopleShortcodeProcessor.php`
- **Key Functionality**:
  - Handles people content.
  - Manages term relationships.
- **Methods**:
  - `parse()`: Parses a WordPress show-people shortcode into a Statamic people Bard set, handling categories, display options, and link settings. Trims and lowercases attribute values (e.g., `category='pulmonology-fellows '` is treated as `pulmonology-fellows`) so term lookups and config match regardless of casing or surrounding spaces.
  - `toPageBuilderBlock()`: Builds a page builder block structure for a show-people shortcode, encapsulating all mapping logic. Trims and lowercases attribute values before parsing.
  - `buildTermMap()`: Builds a map of term slugs to their full slugs and parent slugs from XML terms.
  - `getFullTermSlug()`: Constructs the full slug path for a term, recursively prepending ancestor slugs.
  - `getTermsUsed()`: Returns the terms used by show-people shortcodes with their page slugs.
  - `resetTermsUsed()`: Resets the terms used array for testing or cleanup.

#### QuoteProcessor

- **Purpose**: Processes WordPress wp:quote blocks into Tiptap-compatible HTML for Statamic Bard sets.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/QuoteProcessor.php`
- **Key Functionality**:
  - Handles quote formatting.
  - Manages quote attributes.
  - Processes blockquote content and citations.
  - Handles alignment and styling.
- **Methods**:
  - `canProcess()`: Determines if the processor can handle the given DOM node by checking for wp:quote comments.
  - `process()`: Processes a wp:quote block into Tiptap-compatible HTML, handling blockquote content, citations, and alignment.

#### ShortcodeBlockProcessor

- **Purpose**: Processes WordPress wp:shortcode blocks into Tiptap-compatible HTML for Statamic Bard sets.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/ShortcodeBlockProcessor.php`
- **Key Functionality**:
  - Handles block shortcodes.
  - Manages block attributes.
  - Processes shortcode content between wp:shortcode comments.
  - Converts shortcodes to Tiptap-compatible HTML.
- **Methods**:
  - `__construct()`: Initializes the processor with an optional ShortcodeProcessor instance.
  - `canProcess()`: Determines if the processor can handle the given DOM node by checking for wp:shortcode comments.
  - `process()`: Processes a wp:shortcode block into Tiptap-compatible HTML, handling text and element nodes, and converting shortcodes.

#### ShortcodeProcessor

- **Purpose**: Processes WordPress shortcodes into data for Statamic Bard sets or page builder blocks.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/ShortcodeProcessor.php`
- **Key Functionality**:
  - Handles shortcode extraction.
  - Manages shortcode processing.
  - Tracks unaddressed shortcodes.
  - Supports extensible handlers for specific shortcodes.
  - Integrates with term tracking for validation.
  - Delegates page builder block construction to the handler's `toPageBuilderBlock()` method, ensuring all mapping logic is encapsulated in the specialized processor.
- **Methods**:
  - `extract()`: Extracts shortcode data from text, parsing name and attributes. Normalizes attribute values for `show-people` and `show-team` to lowercase (e.g., `category='Pulmonology-fellowship-faculty'` is treated as `pulmonology-fellowship-faculty`) so term lookups and config match regardless of source casing.
  - `toTiptapHtml()`: Converts shortcode data to HTML for Tiptap setContent.
  - `process()`: Processes shortcode data into a Bard set or delegates page builder block construction to the handler's `toPageBuilderBlock()` method.
  - `processShortcodesInContent()`: Processes shortcodes in content, converting supported ones to Tiptap HTML.
  - `trackUnaddressedShortcode()`: Tracks an unhandled shortcode as an unaddressed node, unless suppressed.
  - `getUnaddressedNodes()`: Gets unaddressed shortcodes for reporting.
  - `registerHandler()`: Registers a new shortcode handler.
  - `getHandlers()`: Returns the registered shortcode handlers.

#### TablePressBlockProcessor

- **Purpose**: Processes WordPress `wp:tablepress/table` blocks into Statamic Bard sets.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/TablePressBlockProcessor.php`
- **Key Functionality**:
  - Handles table blocks.
  - Manages table structure.
  - Converts TablePress blocks into table Bard sets.
  - Extracts table IDs from JSON attributes.
  - Stores IDs for table import processing.
- **Methods**:
  - `canProcess()`: Determines if the processor can handle the given DOM node by checking for `wp:tablepress/table` comments.
  - `process()`: Processes a `wp:tablepress` block into Tiptap-compatible HTML, handling table IDs and generating Bard sets.

#### TablePressShortcodeProcessor

- **Purpose**: Processes WordPress `[table id=X /]` shortcodes for Statamic Bard sets.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/TablePressShortcodeProcessor.php`
- **Key Functionality**:
  - Handles table shortcodes.
  - Manages table data.
  - Converts TablePress shortcodes into table Bard sets.
  - Extracts table IDs from attributes.
  - Maintains a static cache of table IDs for import triggering.
- **Methods**:
  - `parse()`: Parses shortcode attributes into a Bard set structure, handling table IDs and generating initial field values.
  - `toPageBuilderBlock()`: Builds a page builder block structure for a table shortcode, encapsulating all mapping logic.
  - `addTableId()`: Adds a table ID to the cache for import triggering and linking.
  - `getTableIds()`: Retrieves cached table IDs in order of appearance.
  - `clearTableIds()`: Clears cached table IDs to prevent reuse across import cycles.

#### TableProcessor

- **Purpose**: Processes WordPress `wp:table` blocks into Tiptap-compatible HTML for Statamic Bard sets.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/TableProcessor.php`
- **Key Functionality**:
  - Handles table formatting.
  - Manages table structure.
  - Processes table sections (thead, tbody, tfoot).
  - Handles table attributes and styling.
  - Manages table captions and alignment.
  - Converts table data into Tiptap-compatible HTML.
- **Methods**:
  - `canProcess()`: Determines if the processor can handle the given DOM node by checking for `wp:table` comments.
  - `process()`: Processes a `wp:table` block into Tiptap-compatible HTML, handling table structure, attributes, and data.
  - `getInnerHTML()`: Gets the inner HTML of a DOM element as a string.

#### MinervaKBProcessor

- **Purpose**: Processes MinervaKB articles from WordPress XML export into Statamic `knowledge_bases` collection.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/MinervaKBProcessor.php`
- **Key Functionality**:
  - Manages the hierarchical structure of MinervaKB content.
  - Creates a top-level knowledge base entry for the site.
  - Creates topic entries as children of the knowledge base.
  - Creates article entries as children of topics.
  - Maps MinervaKB's taxonomy-based structure to Statamic's hierarchical collection structure.
- **Methods**:
  - `process()`: Orchestrates the entire MinervaKB import process.
  - `createKnowledgeBaseEntry()`: Creates the top-level knowledge base entry.
  - `processTopics()`: Processes topics from `wp:term` elements.
  - `processArticles()`: Processes articles from `wp:post` elements.

### Image Processor Classes

The following classes in `src/Services/WordPressImport/Utilities/Processors/ImageProcessor` handle image processing tasks:

#### AlignmentExtractor

- **Purpose**: Extracts and translates image alignment for Statamic.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/ImageProcessor/AlignmentExtractor.php`
- **Key Functionality**:
  - Processes image alignment.
  - Manages alignment attributes.
  - Maps WordPress alignment classes to Statamic format.
  - Supports multiple alignment sources (block attributes, figure classes, image classes).
  - Handles alignment translation with context-specific mappings.
- **Methods**:
  - `process()`: Extracts alignment from block attributes, figure classes, or image classes, prioritizing block attributes and translating WordPress values to Statamic format.

#### AltTextProcessor

- **Purpose**: Processes alt text for images, setting `alt_override` if needed.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/ImageProcessor/AltTextProcessor.php`
- **Key Functionality**:
  - Handles alt text extraction.
  - Manages alt text attributes.
  - Compares image alt text with attachment alt text.
  - Sets `alt_override` when needed.
  - Tracks alt text differences for logging.
- **Methods**:
  - `process()`: Compares and sets `alt_override` for the image, handling attachment alt text and image alt attributes.

#### AssetImporterProcessor

- **Purpose**: Imports image assets for Statamic.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/ImageProcessor/AssetImporterProcessor.php`
- **Key Functionality**:
  - Handles image importing.
  - Manages asset metadata.
  - Resolves attachment by URL first: when an img has both a `src` URL and a `wp-image-N` class, looks up the XML attachment whose `wp:attachment_url` matches the `src`; if found, uses that attachment’s `wp:post_id` so replaced images in WordPress content import as the displayed image (e.g., img src `.../Jon-Blevins.jpg` with class `wp-image-562` uses attachment 865 for Jon-Blevins, not 562).
  - Falls back to attachment ID from the class when no URL match is found.
  - Imports images based on the resolved attachment ID or src URLs.
  - Prefers full-size images for import.
  - Utilizes `AssetImporter`'s internal caching.
  - Provides fallback import strategies.
- **Methods**:
  - `process()`: Imports an image asset and stores its path, resolving attachment by URL then ID, with fallback matching and direct URL imports.

#### BardSetBuilder

- **Purpose**: Builds Statamic Bard image sets from processed data.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/ImageProcessor/BardSetBuilder.php`
- **Key Functionality**:
  - Creates Bard-compatible image sets.
  - Manages set structure.
  - Constructs ProseMirror-compatible JSON arrays.
  - Handles image path, alignment, caption, and alt override values.
  - Generates unique IDs for Bard sets.
- **Methods**:
  - `process()`: Builds the final Bard image set, creating a set with fields for image, align, caption, and alt_override using context data.

#### CaptionExtractor

- **Purpose**: Extracts captions from image figure elements for Statamic Bard sets.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/ImageProcessor/CaptionExtractor.php`
- **Key Functionality**:
  - Processes image captions.
  - Manages caption attributes.
  - Extracts caption text from `<figcaption>` elements.
  - Handles missing captions with empty strings.
  - Skips extraction for `[caption]` shortcodes.
- **Methods**:
  - `process()`: Extracts caption from the figure element or skips for `[caption]` shortcodes, handling caption text extraction and storage.

#### HtmlParser

- **Purpose**: Parses HTML to extract image elements for processing.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/ImageProcessor/HtmlParser.php`
- **Key Functionality**:
  - Handles HTML parsing.
  - Manages image attributes.
  - Extracts `<img>` tags and parent `<figure>` elements.
  - Processes image source URLs and alt text.
  - Traverses DOM to find related elements.
  - Validates image elements and attributes.
- **Methods**:
  - `process()`: Parses HTML to extract `<img>` tag and related elements, handling DOM traversal and attribute extraction.

#### ImageProcessingStepInterface

- **Purpose**: Interface for image processing steps in the `ImageProcessor` coordinator.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/ImageProcessor/ImageProcessingStepInterface.php`
- **Key Functionality**:
  - Defines contract for image processing tasks.
  - Ensures consistent image handling.
  - Manages processing context between steps.
  - Supports asset importing and logging.
  - Enables modular image processing pipeline.
- **Methods**:
  - `process()`: Processes a specific step in the image processing pipeline, handling context data and returning updated context.

#### WpImageBlockProcessor

- **Purpose**: Processes WordPress `wp:image` blocks for the image processing pipeline.
- **File Path**: `src/Services/WordPressImport/Utilities/Processors/ImageProcessor/WpImageBlockProcessor.php`
- **Key Functionality**:
  - Handles `wp:image` blocks.
  - Manages block attributes.
  - Processes `<figure>` elements and their content.
  - Extracts image data and attributes.
  - Delegates image processing to `ImageProcessor`.
  - Generates Tiptap-compatible HTML.
- **Methods**:
  - `__construct()`: Initializes the processor with an optional `ImageProcessor` instance.
  - `canProcess()`: Determines if the processor can handle the given DOM node by checking for `wp:image` comments.
  - `process()`: Processes a `wp:image` block into Tiptap-compatible HTML, handling figure elements and delegating to `ImageProcessor`.

## Tiptap Classes

The `src/Services/WordPressImport/Tiptap` directory contains classes that handle the conversion of WordPress content into Statamic Bard-compatible ProseMirror JSON format. These classes extend Tiptap's core functionality to handle WordPress-specific content structures and ensure proper rendering in Statamic.

### Editor

- **Purpose**: Custom Tiptap Editor extension for WordPress content import.
- **File Path**: `src/Services/WordPressImport/Tiptap/Editor.php`
- **Key Functionality**:
  - Extends base Tiptap Editor for WordPress content processing.
  - Post-processes ProseMirror JSON output.
  - Wraps text nodes under `listItem` nodes in `paragraph` nodes.
  - Deduplicates marks (e.g., multiple `strike` marks).
  - Ensures correct JSON structure for Statamic Bard fields.
- **Methods**:
  - `setContent()`: Processes input and applies post-processing to ensure correct document structure.
  - `wrapTextNodesInListItems()`: Wraps text nodes directly under listItem nodes in paragraph nodes.
  - `deduplicateMarks()`: Removes duplicate marks from text nodes.
  - `processNode()`: Recursively processes nodes to wrap text nodes in paragraphs.
  - `processNodeForMarks()`: Recursively processes nodes to deduplicate marks.

### Node Classes

The following classes are located in `src/Services/WordPressImport/Tiptap/Nodes` and provide custom Tiptap node implementations for handling WordPress content structures. These nodes extend Tiptap's base node classes to add WordPress-specific parsing and rendering capabilities, ensuring proper conversion of WordPress content into Statamic Bard-compatible ProseMirror JSON format.

#### ListItem

- **Purpose**: Custom Tiptap ListItem node extension for WordPress content import.
- **File Path**: `src/Services/WordPressImport/Tiptap/Nodes/ListItem.php`
- **Key Functionality**:
  - Extends base ListItem node to handle WordPress list structures.
  - Prevents automatic wrapping of mixed content in paragraph nodes.
  - Ensures correct nesting of lists and paragraphs.
  - Maintains proper JSON structure for Statamic Bard fields.
- **Methods**:
  - `parseHTML()`: Defines HTML parsing rules for listItem nodes.
  - `wrapper()`: Prevents automatic wrapping of listItem content.
  - `toProseMirror()`: Converts listItem nodes to ProseMirror JSON format.

#### Set

- **Purpose**: Tiptap node for parsing Statamic Bard sets from HTML elements.
- **File Path**: `src/Services/WordPressImport/Tiptap/Nodes/Set.php`
- **Key Functionality**:
  - Handles parsing of Bard set elements.
  - Manages set type detection and attribute extraction.
  - Processes boolean and integer fields.
  - Generates unique IDs for sets.
  - Delegates rendering to specialized set renderers.
- **Methods**:
  - `parseHTML()`: Defines parsing rules for set elements.
  - `renderHTML()`: Delegates rendering to appropriate set renderer.
  - `toProseMirror()`: Converts set nodes to ProseMirror JSON format.

#### Set Classes

The following classes are located in `src/Services/WordPressImport/Tiptap/Nodes/Sets` and serve as specialized renderers for different types of Statamic Bard sets. They work in conjunction with the main `Set` node class (`src/Services/WordPressImport/Tiptap/Nodes/Set.php`), which handles the initial parsing of Bard set elements and delegates the rendering to these specialized classes based on the set type. This separation of concerns allows for modular handling of different set types while maintaining a consistent interface for Tiptap processing.

##### AbstractSetRenderer

- **Purpose**: Base class for rendering Statamic Bard sets to HTML for Tiptap processing.
- **File Path**: `src/Services/WordPressImport/Tiptap/Nodes/Sets/AbstractSetRenderer.php`
- **Key Functionality**:
  - Provides common rendering logic for Bard sets.
  - Manages set type and attribute handling.
  - Converts Bard sets to HTML elements.
  - Preserves attributes for re-parsing.
- **Methods**:
  - `__construct()`: Initializes the renderer with a set type.
  - `renderHTML()`: Renders a set node to HTML for Tiptap processing.

##### GenericSetRenderer

- **Purpose**: Generic renderer for Statamic Bard sets with standard div-based output.
- **File Path**: `src/Services/WordPressImport/Tiptap/Nodes/Sets/GenericSetRenderer.php`
- **Inheritance**: Extends [`AbstractSetRenderer`](#abstractsetrenderer)
- **Key Functionality**:
  - Handles rendering of standard Bard sets.
  - Supports file, form, image, and video sets.
  - Maps to respective data-set-type values.
- **Methods**:
  - `__construct()`: Creates a renderer for a specific set type.

##### PullQuote

- **Purpose**: Utility class for rendering Statamic pull_quote Bard sets.
- **File Path**: `src/Services/WordPressImport/Tiptap/Nodes/Sets/PullQuote.php`
- **Inheritance**: Extends [`AbstractSetRenderer`](#abstractsetrenderer)
- **Key Functionality**:
  - Renders pull_quote sets to blockquote elements.
  - Handles quote content formatting.
  - Manages alignment and styling.
  - Processes text marks and links.
- **Methods**:
  - `__construct()`: Initializes the renderer for pull_quote sets.
  - `renderHTML()`: Renders a pull_quote set node to HTML.

##### Shortcode

- **Purpose**: Utility class for parsing and rendering WordPress shortcodes into Statamic Bard sets.
- **File Path**: `src/Services/WordPressImport/Tiptap/Nodes/Sets/Shortcode.php`
- **Inheritance**: Extends [`AbstractSetRenderer`](#abstractsetrenderer)
- **Key Functionality**:
  - Handles shortcode-based set parsing.
  - Supports future shortcode-based sets.
- **Methods**:
  - `__construct()`: Initializes the shortcode renderer.
  - `renderHTML()`: Renders a shortcode-based set node to HTML.

##### Table

- **Purpose**: Utility class for rendering Statamic table Bard sets.
- **File Path**: `src/Services/WordPressImport/Tiptap/Nodes/Sets/Table.php`
- **Inheritance**: Extends [`AbstractSetRenderer`](#abstractsetrenderer)
- **Key Functionality**:
  - Renders table sets to figure elements.
  - Handles table structure and formatting.
  - Provides placeholder table rendering.
- **Methods**:
  - `__construct()`: Initializes the renderer for table sets.
  - `renderHTML()`: Renders a table set node to HTML.

## Configuration

The command uses a dedicated configuration file at `config/wordpress-import.php` to manage import settings. This file defines the following options:

- `upload_images`: Controls whether to import image assets (default: `true`)
- `upload_files`: Controls whether to import file assets (default: `true`)
- `import_tags`: Controls whether to import tags (default: `true` when not set). When `true`, WordPress `post_tag` and `kbtag` terms are imported as entries in the site-scoped `post_tags` and `knowledge_base_tags` collections (wordpress-importer-archive#1220). When `false`, neither is imported. The logo list groupings (`logos_item_categories`, `logos_item_labels`) are not affected by this setting.
- `measure_filesize`: Controls whether to measure directory filesize at start and end of import (default: `true`). Set to `false` via `WORDPRESS_IMPORT_MEASURE_FILESIZE=false` to skip measurement.

These settings can be configured via environment variables in `.env`:
```env
WORDPRESS_IMPORT_MEASURE_FILESIZE=true
WORDPRESS_IMPORT_IMAGES=true
WORDPRESS_IMPORT_FILES=true
WORDPRESS_IMPORT_TAGS=true
```
Set `WORDPRESS_IMPORT_TAGS=false` to disable import of post tags and knowledge base tags. When unset, tags are imported (default).

### Migration API

The migration API client (`MigrationApi\Client`) reads **one** key under `migration_api`:

- `migration_api.per_page`: Page size to request (default: `100`, clamped to the API's maximum of `200`).

```php
// config/wordpress-import.php, in the host application
'migration_api' => [
    'per_page' => env('UAMSWP_MIGRATION_API_PER_PAGE', 100),
],
```

```env
UAMSWP_MIGRATION_API_PER_PAGE=100
```

A page size arriving as the string `env()` returns is accepted and cast; a non-numeric one raises rather than quietly falling back to the default.

**The network address and the API key are not configuration.** `Client::forRun($baseUrl, $key)` takes both from what the operator pastes when a run starts, and neither survives the process — the estate is 26 separate multisite networks each issuing its own key, so configuration able to address them all would hold 26 live credentials on disk for a job that reads one network at a time (wordpress-importer-archive#982). `Client::fromConfig()` is gone.

`base_url` and `secret` were host-app configuration until then and are now read by nothing. Setting them has no effect; the consuming application removes them in [`UAMS-Web/uams-statamic-archive#2358`](https://github.com/UAMS-Web/uams-statamic-archive/issues/2358).

The address is refused unless it is an absolute `https://` URL carrying a host, and an empty key is refused rather than sent — the API answers `401` to a missing header, so letting it through would report an operator's slip as an authentication failure against a network that is fine.

### Cleaning API-sourced content

`news.uams.edu`'s WXR export is cleaned with [`uams-web/wordpress-wxr-cleanup`](https://github.com/UAMS-Web/wordpress-wxr-cleanup) before it is imported. When the same site is read through the migration API, the importer runs that cleaner itself: `WxrMaterializer` passes every part it writes through `Transform::withDefaultRules()`, the pipeline the cleaner's CLI runs. So a post maps the same way from either source (#1303).

Only listed sites are cleaned, matched against the host of the site's `base_blog_url`:

- `wxr_cleanup.sites`: hosts whose API content is cleaned (default: `['news.uams.edu']`).

```php
// config/wordpress-import.php, in the host application
'wxr_cleanup' => [
    'sites' => ['news.uams.edu'],
],
```

**Add a site only if its export is cleaned before import.** The cleaner removes every HTML comment, including the `<!-- wp:… -->` block delimiters that block-built content is parsed from. 240 of the 259 exports in the corpus carry block markup.

A part the cleaner cannot parse is refused rather than overwritten: the cleaner hides libxml errors and would return an empty document, so the materializer checks that every `<item>` survived and raises otherwise.

### Vanity redirect domains

A vanity domain is not a Statamic site, so its redirect rules are stored in the host application's `redirect_domains` collection, one entry per rule, rather than in a site's `redirects` global set (`UAMS-Web/uams-statamic-archive#2594`, #1306). An import routes there when the host of the export's `wp:base_blog_url` matches the list exactly, ignoring case:

- `redirect_domains.hosts`: the vanity domains (default: `['uams.health', 'uams.info', 'uamsbaptist.health', 'go.uams.edu', 'gouams.com']`). The first three run Safe Redirect Manager; `go.uams.edu` and `gouams.com` run Redirection and Pretty Links (wordpress-importer-archive#1307).

```php
// config/wordpress-import.php, in the host application
'redirect_domains' => [
    'hosts' => ['uams.health', 'uams.info', 'uamsbaptist.health', 'go.uams.edu', 'gouams.com'],
],
```

Each entry carries the domain as `host`, the rule's WordPress post id as `wordpress_post_id`, plus the rule's `source`, `use_regex`, target (`target_url`, or `target_entry` when the target resolves to an imported entry), `response`, `order` and `notes`, and is published with `enabled` on.

- **Unpublished rules are skipped**, because the collection holds what the domain serves. The run reports how many.
- **A re-run updates the entry with the same host and source** rather than adding another. The redirect overwrite prompt does not apply to this path.
- **Published rules that share a source become one entry, holding the rule WordPress serves** (#108). WordPress answers a path with the first rule that matches it. Pretty Links redirects on `init` at priority 1, Redirection on `init` at priority 10, and Safe Redirect Manager on `parse_request` or `template_redirect`, so they are tried in that order. Within Redirection or Safe Redirect Manager, the rule with the lowest position (`order`) is tried first, then the lowest id. Every other rule with that source is shadowed: it is not written, and the run prints it with the rule that serves the path. Sources are compared ignoring case, as the entry lookup is: Statamic's Stache compares a `where()` value in lower case, so `/Flu` and `/flu` reach one entry. The same pairs are in the end-of-import warnings summary, under `redirects` / `shadowed_rules`, as `host`, `source`, `served`, `served_source` and `shadowed`. The summary line counts them, so on a first run the rules read equal `created`, `updated`, `unpublished skipped` and `shadowed` together. A re-run picks the same rule.
- **A rule unpublished or deleted since an earlier run is disabled, not deleted** (wordpress-importer-archive#1311). Its entry gets `enabled` off and stays in the collection, so the redirect stops and the change can be undone. It is re-enabled if the rule is published again. Only entries the import wrote are considered: each carries the rule's `wordpress_post_id`, and an entry an editor authored in the control panel has none. The run's summary counts the entries it disabled.
- **A response the collection's blueprint does not offer** (anything but 301, 302, 303, 307 or 308) is kept as WordPress served it, and counted in the run's output.
- **The collection is the host application's.** If it does not exist, the run says so and writes nothing.

#### Redirection and Pretty Links rules

Neither plugin stores its rules as posts, so they are not in a WXR export. A `--source=api` run of a listed domain reads them from the migration API's `redirects/redirection` and `redirects/pretty-links` endpoints (`UAMS-Web/uamswp-migration-api-archive#374`), and the materializer writes each rule into the export as a `redirect_rule` item, which then follows the path above (`RedirectPluginRules`, #1307). A `--source=file` run does not read them. Each item's `wordpress_post_id` is the plugin and the rule's id, such as `redirection-12` or `pretty-links-4`, so it cannot collide with a Safe Redirect Manager rule's post id.

- **A plugin the site does not run is skipped.** The endpoints answer `uamswp_migration_api_redirect_plugin_not_loaded` or `..._not_active` for it. Any other error, including the plugin's tables being missing or unreadable, fails the run.
- **Redirection:** a rule is written published only when it and its group are both enabled. The rule's `position` becomes `order` and its title becomes `notes`. A rule that matches on anything but the URL (login state, referrer, user agent, IP, cookie, header, role, language), or whose action is not a redirect to a URL, is not imported.
- **Pretty Links:** every link the endpoint serves is written published, with the slug as the source (`/` plus the slug) and the name and description as `notes`. A 301, 302 or 307 keeps its status; any other type, such as a cloaked link, is written as a 302. A pixel or payment link, which has no target, is not imported.
- **What the collection cannot express is listed per domain, never dropped silently.** The run prints, for each plugin, how many rules were served and how many were written, then each unsupported feature with the number of rules carrying it: rules not imported and why, click tracking, query-string forwarding, the `X-Robots-Tag` a nofollow or sponsored link sends, case-insensitive and trailing-slash matching, and rules served by Redirection's web server module. The same list is in the end-of-import warnings summary, under `redirects`.

## Troubleshooting

- Ensure the XML file path is correct and the file is a valid WordPress export.
- Check `storage/logs/laravel.log` for detailed error messages if the import fails.
- Verify that required collections and taxonomies are set up in Statamic before import.
- Clear cache after enabling new sites or collections for content to appear correctly.

### Memory Issues

The importer now uses streaming XML parsing to prevent memory exhaustion with large files. If you encounter memory issues:

- The streaming parser automatically handles files of any size without loading the entire XML into memory.
- Large XML files (1.7MB+) are processed efficiently using XMLReader.
- Attachments and terms are streamed rather than loaded all at once.
- If memory issues persist, consider increasing PHP memory limit in your environment.

### Filesize Measurement

When `WORDPRESS_IMPORT_MEASURE_FILESIZE` is not set to `false` in `.env` (default: enabled), the importer measures and reports the filesize difference of the codebase before and after the import process:

- **Initial Measurement**: At the start of the import process, the command measures the total filesize of the `app` directory and displays it in megabytes.
- **Final Measurement**: When the user indicates they are done importing content (by answering "No" to "Would you like to import more content?"), the command measures the final filesize of the `app` directory.
- **Difference Display**: The command calculates and displays the filesize difference in multiple units:
  - Bytes
  - Kilobytes (using decimal conversion: 1 MB = 1000 KB)
  - Megabytes (using decimal conversion: 1 MB = 1000 KB)
  - Gigabytes (using decimal conversion: 1 GB = 1000 MB)

This feature helps track the impact of the import process on the codebase size, particularly useful for monitoring the addition of new content files, assets, or generated data during the import.

## Mixed Content Handling

The WordPress import system now provides enhanced support for processing pages that contain a mix of ACF blocks and standard WordPress content. This capability is essential for handling complex WordPress pages that combine custom ACF blocks with traditional WordPress content blocks.

### Mixed Content Processing

When a WordPress page contains both ACF blocks (e.g., `<!-- wp:acf/cta -->`) and standard WordPress content (e.g., paragraphs, headings, lists), the system:

1. **Detects ACF presence**: Uses `ContentProcessor::hasAcfBlocksInContent()` (and `PageEntryCreator::hasPageBuilderBlocks()` for blueprint selection) to identify pages with ACF block comments (`wp:acf/*`, `wp:minervakb/*`, `wp:rss`), LiveWhale widget HTML (`div.lwcw` with `data-options` containing `id=`), or blogroll shortcode (`[blogroll ...]`), triggering landing_page blueprint and page_builder processing.

2. **Preserves content order**: Maintains the original sequence of ACF and non-ACF content as it appears in the XML, ensuring the final page_builder array reflects the intended layout.

3. **Accumulates non-ACF content**: Groups consecutive non-ACF content into single `uamsweb_block_content` sets between ACF blocks or at content boundaries.

4. **Robust finalization**: Ensures all accumulated content is properly finalized and added to the page_builder array, even when the final node is not processed by ACF or non-ACF parsers.

5. **Duplication prevention**: Prevents ACF blocks from being duplicated during finalization by checking if the last node is already an ACF block before making the final call to `PageBuilderParser::parseNode()`.

### Implementation Details

The mixed content handling is implemented through several key components:

- **ContentProcessor**: Makes a final call to `PageBuilderParser::parseNode()` after processing all nodes to trigger finalization logic. This finalization logic addresses a specific issue with mixed ACF and non-ACF content where accumulated non-ACF content between ACF blocks might not be properly finalized and added to the page_builder array. However, the system prevents duplication of ACF blocks by checking if the last node is already an ACF block before making the final call.

- **PageBuilderParser**: Uses static variables to accumulate content across multiple calls and forces finalization for unprocessed final nodes.

- **AcfBlockParser**: Processes ACF blocks and returns appropriate skip counts for block pairs.

- **NonAcfBlockParser**: Handles standard WordPress content and provides finalization methods for accumulated content.

### Duplication Prevention Mechanism

The finalization logic includes a critical duplication prevention mechanism:

- **Problem**: The final call to `PageBuilderParser::parseNode()` could cause the last ACF block to be processed again, resulting in duplication in the page_builder array.

- **Solution**: Before making the final call, the system checks if the last node is an ACF block by examining the DOM comment content for the pattern `^wp:acf/`.

- **Implementation**: If the last node is an ACF block, the final call is skipped, preventing duplication while preserving the mixed content finalization functionality.

- **Benefit**: This ensures that pages with ACF blocks at the end maintain their correct structure without duplication, while still benefiting from the robust finalization logic for mixed content scenarios.

### Example Output

A page with mixed content might produce a page_builder array like:
```yaml
page_builder:
  - type: uamsweb_block_content
    # Standard WordPress content (paragraphs, headings)
  - type: uamsweb_block_cta
    # ACF CTA block
  - type: uamsweb_block_content
    # More standard WordPress content
```

This ensures that the imported content maintains its original structure and flow while being properly organized for Statamic's page_builder system.

## Modules Template Support

The WordPress import system now supports processing WordPress pages that use the `templates/modules.php` template, which corresponds to ACF blocks within Gutenberg but with enforceable validation. This template uses a different data structure (`uamswp_modules`) compared to standard ACF blocks, requiring specialized parsing.

### Modules Template Processing

When a WordPress page uses the modules template, the system:

1. **Detects modules template**: Identifies pages with `uamswp_modules` metadata in the WordPress XML export.

2. **Routes to ModulesParser**: Uses the `ModulesParser` class as a dispatcher to handle modules template data conversion.

3. **Delegates to specialized parsers**: Routes each module type to its corresponding specialized block parser using the `parseModule()` method.

4. **Maintains SRP compliance**: Each parser handles both ACF block data (via `parse()`) and modules template data (via `parseModule()`).

5. **Extracts module data from postmeta**: Extracts all module field data from the full postmeta array, ensuring that fields like `stacked_heading`, `link_list_heading`, and other module-specific fields are available to each parser.

6. **Skips content:encoded parsing**: For modules template pages, the system completely skips parsing of `<content:encoded>` and instead relies exclusively on the modules metadata to build the `page_builder` field.

### Supported Module Types

The system supports 12 module types, each mapped to a specialized parser:

- `modules_text_stacked` → StackedParser
- `modules_link_list` → LinkListParser  
- `modules_action_bar` → ActionBarParser
- `modules_call_out` → CallOutParser
- `modules_cta` → CtaParser
- `modules_news_grid` → NewsParser
- `modules_text_overlay` → OverlayParser
- `modules_uams_gallery` → GalleryParser
- `modules_uams_content` → ContentParser
- `modules_uams_livewhale` → LiveWhaleParser
- `modules_uams_section` → SectionParser
- `modules_image_side` → SideBySideParser

### Implementation Details

The modules template support is implemented through several key components:

- **ModulesParser**: Acts as a coordinator that delegates parsing to specialized block parsers, following the Single Responsibility Principle. Extracts module data from the full postmeta array to ensure all module fields are available.

- **Specialized Block Parsers**: Each parser now includes a `parseModule()` method alongside the existing `parse()` method to handle both ACF block and modules template data formats.

- **Data Structure Differences**: Modules template data uses prefixed field names (e.g., `uamswp_modules_0_action_bar_heading`) compared to ACF block data, requiring different extraction logic.

- **Link Processing**: All parsers use `LinkHelper` utilities for consistent link URL and target extraction, adhering to DRY principles.

- **Memory Efficiency**: All parsers use `XmlStreamParser` instead of `XmlParser` for better memory management during import.

- **Content Field Handling**: For modules template pages, the system does not set a `content` field, relying exclusively on the `page_builder` field populated by the modules parser.

### Example Data Structure

Modules template data structure in postmeta:
```php
[
    'uamswp_modules' => 'a:3:{i:0;s:18:"modules_text_stacked";i:1;s:18:"modules_text_stacked";i:2;s:16:"modules_link_list";}',
    'uamswp_modules_0_stacked_heading' => 'Strategic Sourcing and Contracting',
    'uamswp_modules_0_stacked_background_color' => 'bg-white',
    'uamswp_modules_0_stacked_section' => '5',
    'uamswp_modules_0_stacked_section_0_stacked_section_heading' => 'Informatics',
    'uamswp_modules_0_stacked_section_0_stacked_section_body' => 'The informatics department...',
    // ... more fields
]
```

This is converted to Statamic page_builder format:
```yaml
page_builder:
  - type: uamsweb_block_stacked
    block_heading: Strategic Sourcing and Contracting
    background_color: bg-white
    items:
      - heading: Informatics
        body: The informatics department...
        # ... more item data
```

### Unsupported Module Types

The system tracks unsupported module types for future implementation:
- `modules_hero`
- `modules_post_tile` 
- `modules_post_tiles`

These are logged as warnings during import to inform users of missing functionality.

### FieldDefaultHelper

- **Purpose**: Fetches the default value for a Statamic field from a fieldset or blueprint, resolving references and config overrides as needed.
- **File Path**: `src/Services/WordPressImport/Utilities/FieldDefaultHelper.php`
- **Key Functionality**:
  - Provides static methods to retrieve the default value for a field in a fieldset or blueprint.
  - Resolves blueprint field references to fieldset fields and applies config overrides if present.
  - Supports dot notation references (e.g., `fieldset.handle`) and blueprint/fieldset handle + field handle lookups.
  - Handles both inline and referenced field definitions.
- **Methods**:
  - `getDefaultValueForField($fieldReference)`: Returns the default value for a field given a dot notation reference (e.g., `uamsweb_header_selector_basic.title_options`).
  - `getDefaultValueForFieldsetField($fieldsetHandle, $fieldHandle)`: Returns the default value for a field in a fieldset.
  - `getDefaultValueForBlueprintField($blueprintHandle, $fieldHandle)`: Returns the default value for a field in a blueprint, resolving references to fieldset fields as needed and applying config overrides if present.
