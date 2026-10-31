<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests\Support;

use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A buffered console output that reads back with `\n` line endings on every platform.
 *
 * Symfony ends each line with `PHP_EOL`, which is `\r\n` on Windows, so a test comparing the
 * whole buffer against `\n` text passes on Linux and fails on Windows (#186). A test that
 * asserts line endings exactly uses this rather than spelling `PHP_EOL` into each expectation.
 */
final class LfBufferedOutput extends BufferedOutput
{
    /**
     * Empty the buffer and return its content, with `\n` line endings.
     *
     * @return string The buffered output
     */
    public function fetch(): string
    {
        return \str_replace(\PHP_EOL, "\n", parent::fetch());
    }
}
