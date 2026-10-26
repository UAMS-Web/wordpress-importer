<?php

declare(strict_types=1);

/**
 * Cross-platform wrapper so Herd MCP stdout only contains JSON-RPC lines.
 *
 * The phar sometimes writes "[warning] ..." to stdout, which breaks MCP
 * (expects only JSON). We forward stdin to the phar and only pass stdout
 * lines that start with '{'. Used by .cursor/mcp.json on Windows and macOS.
 *
 * Phar path: set HERD_MCP_PHAR to override. Defaults: macOS
 * /Applications/Herd.app/Contents/Resources/herd-mcp.phar, Windows
 * %USERPROFILE%\.config\herd\bin\herd-mcp.phar.
 */

// Project root directory (parent of .cursor)
$projectRoot = dirname(__DIR__);

// Phar path from env override, or false if unset
$pharPath = getenv('HERD_MCP_PHAR');

// ---
// Resolve phar path from env or platform default when not set
// ---

// Check if phar path is not set or is empty
if (
    $pharPath === false
    || $pharPath === ''
) {
    // macOS: app bundle path; Windows: Herd config path under user profile
    $pharPath = PHP_OS_FAMILY === 'Darwin'
        ? '/Applications/Herd.app/Contents/Resources/herd-mcp.phar'
        : getenv('USERPROFILE').DIRECTORY_SEPARATOR.'.config'
            .DIRECTORY_SEPARATOR.'herd'.DIRECTORY_SEPARATOR.'bin'
            .DIRECTORY_SEPARATOR.'herd-mcp.phar';
}

// ---
// Abort if phar file is missing
// ---

// Check if phar file is missing
if (! is_file($pharPath)) {
    // Write error message to stderr so user sees it
    fwrite(STDERR, "run-herd-mcp.php: phar not found at {$pharPath}".PHP_EOL);

    // Hint to set HERD_MCP_PHAR
    fwrite(STDERR, 'Set HERD_MCP_PHAR to the correct path.'.PHP_EOL);

    // Exit with error code so Cursor knows the server failed
    exit(1);
}

// Herd MCP uses SITE_PATH to resolve the project
putenv("SITE_PATH={$projectRoot}");

// Build php command with phar path safely quoted
$command = 'php -d display_errors=0 -d log_errors=1 '.escapeshellarg($pharPath);

// Run phar with stdin from Cursor; capture stdout to filter
$proc = proc_open(
    $command,
    [
        0 => STDIN,
        1 => ['pipe', 'w'],
        2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
    ],
    $pipes,
    $projectRoot
);

// Abort if process failed to start
if (! is_resource($proc)) {
    // Exit with error code so Cursor knows the server failed
    exit(1);
}

// Pipe connected to phar stdout for line-by-line reading
$stdout = $pipes[1];

// ---
// Forward only JSON-RPC lines (starting with '{') to avoid breaking MCP
// ---

// Read phar stdout line by line
while (($line = fgets($stdout)) !== false) {
    // Only pass lines that look like JSON-RPC (start with '{')
    if (
        str_starts_with(
            trim($line),
            '{'
        )
    ) {
        // Echo JSON-RPC line to Cursor
        echo $line;
    }
}

// Close stdout pipe before proc_close
fclose($stdout);

// Wait for process to finish and exit with its status code
exit(proc_close($proc));
