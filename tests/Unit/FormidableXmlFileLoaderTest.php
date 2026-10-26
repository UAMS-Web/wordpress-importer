<?php

declare(strict_types=1);

/**
 * Tests the Formidable Forms XML loader's `<view>` extraction (issue wordpress-importer-archive#37) and
 * the unmapped-excerpt guard folded in from issue wordpress-importer-archive#40.
 *
 * Issue wordpress-importer-archive#37: the base `XmlFileLoader` parsed only `<form>`/`<field>` nodes and
 * never the `<view>` (`frm_form_actions`) siblings that carry a form's email
 * notifications and on-submit confirmations. Every form therefore arrived with
 * no `views` key and `FormConfigurationManager` early-returned, so zero
 * notifications/confirmations imported. `FormidableXmlFileLoader` overrides
 * `convertXmlToArray()` to attach each action view to its parent form by
 * `<menu_order>` == form `id`.
 *
 * Issue wordpress-importer-archive#40: `FormConfigurationManager::mapNotificationsToEmailConfigurations()`
 * read `FORM_CONFIGURATION_VIEW_MAPPING[$excerpt]` without a `?? null` default,
 * so once views started flowing, an action whose excerpt is not in the map
 * (`api`, `wppost`) raised an "Undefined array key" warning. The guard now
 * coalesces to null so unmapped actions are simply skipped.
 *
 * @command  php vendor/bin/pest tests/Unit/FormidableXmlFileLoaderTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormConfigurationManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableXmlFileLoader;

/**
 * Invoke the loader's protected `convertXmlToArray()` against an XML string.
 *
 * @param  string  $xml  The Formidable export XML to parse
 * @return array<int, array<mixed>> The converted forms array (with attached views)
 */
function formidableXmlConvert(string $xml): array
{
    $loader = new FormidableXmlFileLoader('FormidableForms');

    $method = new ReflectionMethod($loader, 'convertXmlToArray');
    $method->setAccessible(true);

    $forms = $method->invoke($loader, new SimpleXMLElement($xml));
    expect($forms)->toBeArray();

    if (! is_array($forms)) {
        return [];
    }

    $validatedForms = [];
    foreach ($forms as $form) {
        expect($form)->toBeArray();

        if (! is_array($form)) {
            continue;
        }

        $validatedForms[] = $form;
    }

    return $validatedForms;
}

/**
 * Return the first converted form after asserting the list is non-empty.
 *
 * @param  array<int, array<mixed>>  $forms
 * @return array<mixed>
 */
function formidableXmlFirstForm(array $forms): array
{
    expect($forms)->not->toBeEmpty();

    return $forms[0];
}

/**
 * Return a form's views list after asserting it is an array.
 *
 * @param  array<mixed>  $form
 * @return array<mixed>
 */
function formidableXmlFormViews(array $form): array
{
    $views = $form['views'] ?? null;
    expect($views)->toBeArray();

    if (! is_array($views)) {
        return [];
    }

    return $views;
}

/**
 * Return a view entry at the given index.
 *
 * @param  array<mixed>  $views
 * @return array<mixed>
 */
function formidableXmlViewAt(array $views, int $index): array
{
    expect($views)->toHaveKey($index);

    $view = $views[$index] ?? null;
    expect($view)->toBeArray();

    if (! is_array($view)) {
        return [];
    }

    return $view;
}

/**
 * Index converted forms by their numeric id.
 *
 * @param  array<int, array<mixed>>  $forms
 * @return array<int, array<mixed>>
 */
function formidableXmlIndexById(array $forms): array
{
    $byId = [];

    foreach ($forms as $form) {
        $id = $form['id'] ?? null;
        expect($id)->toBeInt();

        if (! is_int($id)) {
            continue;
        }

        $byId[$id] = $form;
    }

    return $byId;
}

/**
 * Build a minimal Formidable export with the given form/view fragments.
 *
 * @param  string  $body  The `<form>` and `<view>` XML fragments
 * @return string A parseable export document
 */
function formidableXmlExport(string $body): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'."\n".
        '<formidable>'."\n".$body."\n".'</formidable>';
}

/**
 * A `<view>` whose `frm_form_actions` action attaches to its parent form.
 *
 * @param  int  $formId  The parent form id (matches <menu_order>)
 * @param  string  $excerpt  The action type (email, on_submit, api, ...)
 * @param  string  $content  The raw JSON action content
 * @return string The `<view>` XML fragment
 */
function formidableXmlActionView(int $formId, string $excerpt, string $content = '{}'): string
{
    return '<view>'.
        '<title>Action</title>'.
        '<content><![CDATA['.$content.']]></content>'.
        '<excerpt><![CDATA['.$excerpt.']]></excerpt>'.
        '<post_id>900'.$formId.'</post_id>'.
        '<post_name>'.$formId.'_'.$excerpt.'</post_name>'.
        '<status>publish</status>'.
        '<menu_order>'.$formId.'</menu_order>'.
        '<post_type>frm_form_actions</post_type>'.
        '</view>';
}

/**
 * A `<form>` fragment with a single field.
 *
 * @param  int  $id  The form id
 * @param  string  $name  The form name
 * @return string The `<form>` XML fragment
 */
function formidableXmlForm(int $id, string $name): string
{
    return '<form>'.
        '<id>'.$id.'</id>'.
        '<name>'.$name.'</name>'.
        '<field><id>'.($id * 10).'</id><type>text</type></field>'.
        '</form>';
}

it('attaches views to their parent form by menu_order and captures each child node', function (): void {
    $forms = formidableXmlConvert(formidableXmlExport(
        formidableXmlForm(1, 'Contact').
        formidableXmlActionView(1, 'email', '{"email_to":"[admin_email]"}').
        formidableXmlActionView(1, 'on_submit', '{"success_msg":"Thanks"}')
    ));

    expect($forms)->toHaveCount(1);

    $form = formidableXmlFirstForm($forms);
    expect($form['id'] ?? null)->toBe(1);

    $views = formidableXmlFormViews($form);
    expect($views)->toHaveCount(2);

    // The loader must capture content as a raw JSON string and the excerpt.
    $firstView = formidableXmlViewAt($views, 0);
    expect($firstView['excerpt'] ?? null)->toBe('email');
    expect($firstView['content'] ?? null)->toBe('{"email_to":"[admin_email]"}');
    expect(formidableXmlViewAt($views, 1)['excerpt'] ?? null)->toBe('on_submit');
});

it('routes each view only to the form whose id equals its menu_order', function (): void {
    $forms = formidableXmlConvert(formidableXmlExport(
        formidableXmlForm(1, 'Contact').
        formidableXmlForm(2, 'Apply').
        formidableXmlActionView(1, 'email').
        formidableXmlActionView(2, 'email').
        formidableXmlActionView(2, 'on_submit')
    ));

    $byId = formidableXmlIndexById($forms);

    expect(formidableXmlFormViews($byId[1]))->toHaveCount(1);
    expect(formidableXmlFormViews($byId[2]))->toHaveCount(2);
});

it('gives an action-less form an empty views array', function (): void {
    $forms = formidableXmlConvert(formidableXmlExport(
        formidableXmlForm(1, 'Contact')
    ));

    $form = formidableXmlFirstForm($forms);

    expect($form)->toHaveKey('views');
    expect(formidableXmlFormViews($form))->toBe([]);
});

it('ignores orphan views and non-frm_form_actions views', function (): void {
    $forms = formidableXmlConvert(formidableXmlExport(
        formidableXmlForm(1, 'Contact').
        // Orphan: no form with id 99.
        formidableXmlActionView(99, 'email').
        // Non-action view sharing form 1's menu_order must be skipped.
        '<view><excerpt><![CDATA[email]]></excerpt><menu_order>1</menu_order>'.
        '<post_type>frm_styles</post_type></view>'
    ));

    expect($forms)->toHaveCount(1);

    $form = formidableXmlFirstForm($forms);
    expect(formidableXmlFormViews($form))->toBe([]);
});

it('maps unmapped excerpts without a PHP warning and yields no email configurations', function (): void {
    $manager = new FormConfigurationManager;

    $method = new ReflectionMethod($manager, 'mapNotificationsToEmailConfigurations');
    $method->setAccessible(true);

    $formData = [
        'id' => 1,
        'name' => 'Bid Specs',
        'fields' => [],
        'views' => [
            ['excerpt' => 'wppost', 'content' => '{}'],
            ['excerpt' => 'api', 'content' => '{}'],
        ],
    ];

    // Promote any PHP warning (e.g. "Undefined array key") to an exception
    // so the missing `?? null` guard would fail this test loudly.
    set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        $configs = $method->invoke($manager, $formData, 'default', null);
    } finally {
        restore_error_handler();
    }

    expect($configs)->toBe([]);
});

it('resolves an XML-sourced view title from the export\'s title, not its action type', function (): void {
    // The export's <view> carries <title> and <post_name>, never post_title or name (wordpress-importer-archive#1121).
    $views = formidableXmlFormViews(formidableXmlFirstForm(formidableXmlConvert(formidableXmlExport(
        formidableXmlForm(1, 'Contact').
        formidableXmlActionView(1, 'email')
    ))));
    $view = formidableXmlViewAt($views, 0);

    // Control: the fixture is the shape measured, title present and post_title absent.
    expect($view)->toHaveKey('title')->not->toHaveKey('post_title');

    expect(FormConfigurationManager::resolveViewTitle($view, 'Unnamed'))->toBe('Action');
});

it('resolves a view title in order, never from the slug, and skips an empty title', function (
    array $view,
    string $expected
): void {
    expect(FormConfigurationManager::resolveViewTitle($view, 'Unnamed'))->toBe($expected);
})->with([
    'title over the action type' => [['title' => 'Send Email', 'excerpt' => 'email'], 'Send Email'],
    'post_title still wins where a source carries it' => [['post_title' => 'Stored', 'title' => 'Send Email', 'excerpt' => 'email'], 'Stored'],
    'the slug is never a title' => [['post_name' => '1_email_1', 'name' => '1_email_1', 'excerpt' => 'email'], 'email'],
    'an empty title falls to the action type' => [['title' => '', 'excerpt' => 'on_submit'], 'on_submit'],
    'nothing at all falls to the default' => [[], 'Unnamed'],
]);

it('keeps attaching views after skipping an orphan or a non-action view', function (): void {
    $forms = formidableXmlConvert(formidableXmlExport(
        formidableXmlForm(1, 'Contact').
        '<view><excerpt><![CDATA[email]]></excerpt><menu_order>1</menu_order>'.
        '<post_type>frm_styles</post_type></view>'.
        formidableXmlActionView(99, 'email').
        formidableXmlActionView(1, 'on_submit')
    ));

    $views = formidableXmlFormViews(formidableXmlFirstForm($forms));

    expect($views)->toHaveCount(1)
        ->and(formidableXmlViewAt($views, 0)['excerpt'] ?? null)->toBe('on_submit');
});
