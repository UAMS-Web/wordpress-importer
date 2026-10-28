<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * The API path of
 * `UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\MigrationApiFormLoader`,
 * reached through `GravityFormsLoader`, whose payload it passes through unchanged: the count
 * it reports, and what it says and logs when the read fails (#100).
 */
uses(StatamicTestCase::class);

/**
 * A command whose output a test can read back.
 *
 * @return array{Command, BufferedOutput} The command and the output it writes to
 */
function migrationApiFormLoaderCommand(): array
{
    $command = new class extends Command
    {
        protected $signature = 'test:migration-api-form-loader';
    };

    $buffer = new BufferedOutput;
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    return [$command, $buffer];
}

it('reports how many definitions it read from the migration API', function (): void {
    Http::fake([
        '*/forms/gravity*' => Http::response([['id' => '1', 'title' => 'A'], ['id' => '2', 'title' => 'B']], 200, ['X-WP-Total' => '2']),
    ]);
    [$command, $buffer] = migrationApiFormLoaderCommand();

    $loader = new GravityFormsLoader;
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    expect($loader->load('', $command))->toHaveCount(2)
        ->and($buffer->fetch())->toContain('  Read 2 GravityForms form definition(s) from the migration API.');
});

it('says and logs why it could not read the definitions, and returns null', function (): void {
    Http::fake([
        '*/forms/gravity*' => Http::response(['code' => 'rest_forbidden'], 500),
    ]);
    $logged = [];
    Log::shouldReceive('error')->andReturnUsing(function (string $message, array $context = []) use (&$logged): void {
        $logged[] = [$message, $context];
    });
    [$command, $buffer] = migrationApiFormLoaderCommand();

    $loader = new GravityFormsLoader;
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'), 7);

    expect($loader->load('', $command))->toBeNull()
        ->and($logged)->toHaveCount(1);

    [$message, $context] = $logged[0];
    $reason = is_string($context['message'] ?? null) ? $context['message'] : '';

    expect($message)->toBe('MigrationApiFormLoader::loadFromApi: GravityForms read failed')
        ->and($context)->toBe(['endpoint' => 'forms/gravity', 'site' => 7, 'message' => $reason])
        ->and($reason)->not->toBe('')
        ->and($buffer->fetch())->toContain('Could not read GravityForms definitions from the migration API: '.$reason);
});
