<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Traits;

/**
 * Trait for a prompt that ends the command run when it has nothing to offer.
 *
 * The exit is a method rather than an inline `exit()`, so a test can override it and observe
 * the path that ends the run, which an `exit()` would otherwise end along with the test (#100).
 */
trait TerminatesCommand
{
    /**
     * End the command run with an exit status.
     *
     * @param  int  $status  The exit status
     */
    protected function terminate(int $status): never
    {
        exit($status);
    }
}
