<?php

declare(strict_types=1);

/**
 * Tests for the Table set renderer, which registers the `table` set type and
 * always renders an empty table inside a `wp-block-table` figure, whatever the
 * node carries (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/TableSetRendererTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\AbstractSetRenderer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\Table;

/**
 * A plain object with the given properties, as tiptap-php hands nodes around.
 *
 * @param  array<string, mixed>  $properties  The properties
 * @return stdClass The object
 */
function tableSetObject(array $properties): stdClass
{
    $object = new stdClass;

    foreach ($properties as $property => $value) {
        $object->{$property} = $value;
    }

    return $object;
}

it('registers the table set type', function (): void {
    expect((new ReflectionProperty(AbstractSetRenderer::class, 'setType'))->getValue(new Table))->toBe('table');
});

it('renders an empty table in a wp-block-table figure, whatever the node holds', function (object $node): void {
    expect((new Table)->renderHTML($node))->toBe([
        'tag' => '<figure class="wp-block-table">',
        'content' => '<table></table>',
    ]);
})->with([
    'empty object' => fn (): object => new stdClass,
    'object with values' => fn (): object => tableSetObject(['attrs' => tableSetObject(['values' => tableSetObject(['rows' => 3])])]),
]);
