<?php

declare(strict_types=1);

/**
 * Cross-platform wrapper to run Laravel Boost MCP.
 *
 * Sets CACHE_STORE=array for fast bootstrap and bumps memory_limit to 1024M
 * when the current limit is lower. Used by .cursor/mcp.json so Cursor's MCP
 * process gets the correct env on both Windows and macOS/Linux.
 */

// ---
// Resolve project root from script location (parent of .cursor)
// ---

// Project root directory (parent of .cursor)
$projectRoot = dirname(__DIR__);

// Change working directory to project root so artisan runs in the project
chdir($projectRoot);

// Use array cache so MCP bootstrap stays fast
putenv('CACHE_STORE=array');

// ---
// Parse current PHP memory_limit from ini to decide if we need to bump
// ---

// Current memory_limit string from php.ini (e.g., 128M, -1)
$memoryLimit = ini_get('memory_limit');

// Whether to bump memory_limit to 1024M before running artisan
$needBump = true;

// ---
// Skip bump when memory is already unlimited
// ---

// Check if memory is unlimited
if ($memoryLimit === '-1') {
    // Set needBump to false when memory is unlimited
    $needBump = false;
} else { // Otherwise
    // Parse numeric value from memory_limit string
    $value = (int) $memoryLimit;

    // Extract unit letter (K, M, G) from ini value
    $unit = strtoupper(preg_replace(
        '/[0-9]/',
        '',
        $memoryLimit
    ));

    // Bytes per unit for K, M, G
    $multipliers = [
        'K' => 1024,
        'M' => 1024 ** 2,
        'G' => 1024 ** 3,
    ];

    // Multiplier for the parsed unit, or 1 if unknown
    $multiplier = $multipliers[$unit] ?? 1;

    // Bump only when current limit is below 1024M
    $needBump = ($value * $multiplier) < (1024 * 1024 * 1024);
}

// Run artisan with higher limit when needed
$command = $needBump
    ? 'php -d memory_limit=1024M artisan boost:mcp'
    : 'php artisan boost:mcp';

// Run Boost MCP with stdio passed through to Cursor
$proc = proc_open(
    $command,
    [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ],
    $pipes,
    $projectRoot
);

// ---
// Abort if process failed to start
// ---

// Check if process failed to start
if (! is_resource($proc)) {
    // Exit with error code so Cursor knows the server failed
    exit(1);
}

// Wait for process to finish and exit with its status code
exit(proc_close($proc));
