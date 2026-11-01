<?php

declare(strict_types=1);

/**
 * Tests for the two thin set renderers: the generic one renders its own set type, and
 * the shortcode one renders `people` unless a type is passed (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/GenericAndShortcodeSetRendererTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\GenericSetRenderer;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\Shortcode;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;

/**
 * A set node whose attrs carry the given values.
 *
 * @param  array<string, mixed>  $values  The set values
 * @return stdClass The node
 */
function genericShortcodeSetNode(array $values): stdClass
{
    $node = new stdClass;
    $node->attrs = new stdClass;
    $node->attrs->values = $values;

    return $node;
}

/**
 * The opening tag a renderer is expected to produce.
 *
 * @param  string  $type  The data-set-type value
 * @param  string  $attributes  The data-set-attr attributes, with their leading spaces
 * @return array<string, string> The rendering configuration
 */
function genericShortcodeSetTag(string $type, string $attributes = ''): array
{
    return ['tag' => '<div data-set-type="'.$type.'"'.SetMarkupNonce::attribute().$attributes.'>'];
}

it('renders a generic set under the type it was created with', function (): void {
    expect((new GenericSetRenderer('uamsweb_set_file'))->renderHTML(genericShortcodeSetNode(['type' => 'file', 'id' => '7'])))
        ->toBe(genericShortcodeSetTag('uamsweb_set_file', ' data-set-attr-id="7"'));
});

it('renders a shortcode set as people when no type is passed', function (): void {
    expect((new Shortcode)->renderHTML(genericShortcodeSetNode(['limit' => '3'])))
        ->toBe(genericShortcodeSetTag('people', ' data-set-attr-limit="3"'));
});

it('renders a shortcode set under the type it is passed, and keeps that type afterwards', function (): void {
    $renderer = new Shortcode;

    expect($renderer->renderHTML(genericShortcodeSetNode([]), 'subpage_list'))
        ->toBe(genericShortcodeSetTag('subpage_list'))
        ->and((new ReflectionProperty($renderer, 'setType'))->getValue($renderer))->toBe('subpage_list');
});

it('starts a shortcode set renderer as a people renderer', function (): void {
    $renderer = new Shortcode;

    expect((new ReflectionProperty($renderer, 'setType'))->getValue($renderer))->toBe('people');
});
