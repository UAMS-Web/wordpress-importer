# MinervaKB Settings Processing Architecture

This directory contains the refactored MinervaKB settings processing components, following the Single Responsibility Principle (SRP) to improve maintainability and testability.

## Architecture Overview

The original `MinervaKbSettingsSelector` class was handling multiple responsibilities, violating SRP. It has been refactored into a coordinator pattern with specialized components:

```
Selector (Coordinator)
├── Prompter (User Interaction)
├── Loader (File Operations)
├── Validator (Content Validation)
└── Extractor (Settings Mapping)
```

## Component Responsibilities

### Selector (Coordinator)
- **Responsibility**: Orchestrates the complete workflow
- **Duties**: 
  - Coordinates interaction between specialized components
  - Manages the overall process flow
  - Provides a clean public interface

### Prompter (User Interaction)
- **Responsibility**: Handles all user interaction and file path collection
- **Duties**:
  - Displays instructions to users
  - Prompts for file path input
  - Validates file existence
  - Manages user confirmation flow

### Loader (File Operations)
- **Responsibility**: Manages file reading and JSON parsing
- **Duties**:
  - Reads file content from filesystem
  - Parses JSON content into PHP arrays
  - Handles file reading errors
  - Manages JSON parsing errors

### Validator (Content Validation)
- **Responsibility**: Validates MinervaKB settings content
- **Duties**:
  - Validates that content appears to be MinervaKB settings
  - Checks for required MinervaKB-specific keys
  - Ensures settings structure is valid
  - Provides meaningful validation error messages

### Extractor (Settings Mapping)
- **Responsibility**: Maps MinervaKB settings to Statamic field values
- **Duties**:
  - Maps settings to knowledge base blueprint fields
  - Maps settings to topic blueprint fields
  - Provides default values for missing settings
  - Maintains mapping logic between WordPress and Statamic

## Benefits of This Refactoring

### 1. Single Responsibility Principle Compliance
Each class now has a single, well-defined responsibility, making the code easier to understand and maintain.

### 2. Improved Testability
Individual components can be unit tested in isolation, with mocked dependencies for comprehensive test coverage.

### 3. Better Maintainability
Changes to one aspect (e.g., user interaction) don't affect other aspects (e.g., file loading), reducing the risk of introducing bugs.

### 4. Enhanced Reusability
Components can be reused in different contexts or combined in new ways without modification.

### 5. Clearer Dependencies
The coordinator pattern makes dependencies explicit and manageable through dependency injection.

### 6. Simplified Structure
All components are in a flat directory structure with concise class names, making navigation easier and reducing unnecessary complexity.

## Usage

The refactored components are automatically handled by Laravel's dependency injection container and can be used through the coordinator:

```php
// The coordinator handles all the complexity internally
$settings = $minervaKbSettingsSelector->select($input, $output, $command);

// Extract specific settings for mapping
$kbSettings = $minervaKbSettingsSelector->extractKnowledgeBaseSettings($settings);
```

## Migration Notes

- The public interface of `Selector` remains unchanged
- All existing functionality is preserved
- Dependencies are automatically injected through Laravel's service container
- No changes required in consuming code
- Simplified flat directory structure for easier navigation
- Concise class names that rely on namespace context for clarity 
