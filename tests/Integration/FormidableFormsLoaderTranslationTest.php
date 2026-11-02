<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * The edges of `FormidableFormsLoader::translateApiForm()` that the sourcing tests leave
 * open: malformed members, keyed or mixed `actions[]`, and the withheld-settings count at
 * its boundaries.
 *
 * @command  php vendor/bin/pest tests/Integration/FormidableFormsLoaderTranslationTest.php
 */
uses(StatamicTestCase::class);

/**
 * Translate one API form, returning the result and whatever the loader printed.
 *
 * @param  array<int|string, mixed>  $form  The form as the endpoint served it
 * @return array{0: mixed, 1: string} The translated form and the command output
 */
function formidableTranslate(array $form): array
{
    $output = new BufferedOutput;
    $command = new class extends Command
    {
        protected $signature = 'test:formidable-translation';
    };
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

    $loader = new FormidableFormsLoader;
    $translated = (new ReflectionMethod($loader, 'translateApiForm'))->invoke($loader, $form, $command);

    return [$translated, $output->fetch()];
}

it('leaves a fields member that is not a list untouched', function (): void {
    [$translated] = formidableTranslate(['id' => 1, 'fields' => 'not a list']);

    expect($translated)->toBe(['id' => 1, 'fields' => 'not a list', 'views' => []]);
});

it('serializes both options members of a field', function (): void {
    [$translated] = formidableTranslate(['id' => 1, 'fields' => [
        ['id' => 5, 'options' => ['a', 'b'], 'field_options' => ['x' => 1]],
        'not a field',
    ]]);

    expect($translated)->toBe(['id' => 1, 'fields' => [
        ['id' => 5, 'options' => '["a","b"]', 'field_options' => '{"x":1}'],
        'not a field',
    ], 'views' => []]);
});

it('translates only the array entries of a keyed actions member, as a list', function (): void {
    [$translated] = formidableTranslate(['id' => 1, 'actions' => [
        'first' => ['type' => 'email', 'title' => 'Send', 'name' => '1_email', 'status' => 'publish', 'id' => 9],
        'second' => 'not an action',
    ]]);

    expect($translated)->toBe(['id' => 1, 'views' => [[
        'excerpt' => 'email',
        'title' => 'Send',
        'post_name' => '1_email',
        'status' => 'publish',
        'post_id' => 9,
    ]]]);
});

it('fills an action with no members with empty strings and no content', function (): void {
    [$translated] = formidableTranslate(['id' => 1, 'actions' => [[]]]);

    expect($translated)->toBe(['id' => 1, 'views' => [[
        'excerpt' => '',
        'title' => '',
        'post_name' => '',
        'status' => '',
        'post_id' => '',
    ]]]);
});

it('passes a string settings member through as the content, not re-encoded', function (): void {
    [$translated] = formidableTranslate(['id' => 1, 'actions' => [['settings' => 'already a string']]]);

    expect($translated)->toBe(['id' => 1, 'views' => [[
        'excerpt' => '',
        'title' => '',
        'post_name' => '',
        'status' => '',
        'post_id' => '',
        'content' => 'already a string',
    ]]]);
});

it('reports a single withheld setting', function (): void {
    [, $output] = formidableTranslate(['id' => 4, 'actions' => [['denied' => ['by_name' => 1]]]]);

    expect($output)->toContain('Formidable form 4: 1 action setting(s) withheld');
});

it('counts past an action whose denied member is not a map', function (): void {
    [, $output] = formidableTranslate(['id' => 4, 'actions' => [
        ['denied' => 'garbled'],
        ['denied' => ['by_name' => 1, 'by_shape' => 1]],
    ]]);

    expect($output)->toContain('Formidable form 4: 2 action setting(s) withheld');
});

it('counts a non-numeric counter as nothing', function (): void {
    [, $output] = formidableTranslate(['id' => 4, 'actions' => [['denied' => ['by_name' => 'many', 'by_shape' => 1]]]]);
    [, $shapeOutput] = formidableTranslate(['id' => 4, 'actions' => [['denied' => ['by_name' => 1, 'by_shape' => 'many']]]]);

    expect($output)->toContain('Formidable form 4: 1 action setting(s) withheld')
        ->and($shapeOutput)->toContain('Formidable form 4: 1 action setting(s) withheld');
});

it('truncates a fractional counter, so half a setting is not reported', function (): void {
    [, $nameOutput] = formidableTranslate(['id' => 4, 'actions' => [['denied' => ['by_name' => '0.5']]]]);
    [, $shapeOutput] = formidableTranslate(['id' => 4, 'actions' => [['denied' => ['by_shape' => '0.5']]]]);

    expect($nameOutput)->toBe('')
        ->and($shapeOutput)->toBe('');
});

it('names the form as a question mark when its id is not scalar', function (): void {
    [, $output] = formidableTranslate(['id' => ['x'], 'actions' => [['denied' => ['by_name' => 1]]]]);

    expect($output)->toContain('Formidable form ?: 1 action setting(s) withheld');
});
