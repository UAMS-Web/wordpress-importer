<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormConfigurationManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableXmlFileLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * The Formidable Forms sourcing seam (wordpress-importer-archive#811).
 *
 * Formidable is the one slice of #695 where the two sources differ by FORMAT: the committed
 * export is XML and the endpoint serves a bespoke JSON shape. The premise these gate is not
 * "the payloads agree" — they do not — but that the endpoint's payload, once translated at
 * the loader, hands the mapping the shape it consumes from a file.
 *
 * **The load-bearing case is the silent one.** The endpoint serves `field_options` as a
 * parsed structure; every mapping guard that reads it keys on `is_string()` and treats a
 * parsed array as absent, so an untranslated payload imports GREEN while dropping all
 * conditional logic. A test that only asserted "the import succeeded" would pass against the
 * unfixed loader — the success is the failure mode — so these assert that the conditional
 * logic SURVIVES.
 *
 * Notification parity is asserted at the seam only: the endpoint's `actions[]` (shipped by
 * `uamswp-migration-api-archive#284`) are translated into the `views[]` the mapping consumes, and what
 * the endpoint withheld from them is reported rather than dropped. Whether the rendered
 * notifications then agree is a host-app question this file does not reach.
 *
 * @command  php vendor/bin/pest tests/Integration/FormidableFormsSourcingTest.php
 */
uses(StatamicTestCase::class);

/**
 * A command for the loader's info/warn/error lines — discarded unless a buffer is supplied.
 */
function formidableLoaderCommand(?BufferedOutput $output = null): Command
{
    $command = new class extends Command
    {
        protected $signature = 'test:formidable-sourcing';
    };
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output ?? new BufferedOutput));

    return $command;
}

/**
 * A field's `field_options` structure carrying conditional logic, as the endpoint serves it
 * — a parsed array, not the serialized string the XML export stores.
 *
 * @return array<string, mixed>
 */
function conditionalFieldOptions(): array
{
    return [
        'hide_field' => ['2'],
        'hide_field_cond' => ['=='],
        'hide_opt' => ['yes'],
        'show_hide' => 'show',
        'any_all' => 'any',
    ];
}

/**
 * One form action as `/forms/formidable` serves it under `actions[]` (uamswp-migration-api-archive#284):
 * `settings` decoded to a map, and the endpoint's own `denied` counters.
 *
 * @param  array<string, mixed>  $overrides  Members to replace on the served shape
 * @return array<string, mixed>
 */
function formidableApiAction(array $overrides = []): array
{
    return \array_merge(
        [
            'id' => 900,
            'type' => 'on_submit',
            'title' => 'Confirmation',
            'name' => '7_on_submit_1',
            'status' => 'publish',
            'created_at' => '2026-01-01 00:00:00',
            'form_id' => 7,
            'settings' => ['success_action' => 'message', 'success_msg' => 'Thanks.'],
            'denied' => ['total' => 0, 'by_name' => 0, 'by_shape' => 0],
        ],
        $overrides
    );
}

/**
 * One Formidable form as `/forms/formidable` serves it: a parsed JSON shape, with
 * `field_options` and `options` as structures rather than strings.
 *
 * @return array<string, mixed>
 */
function formidableApiForm(): array
{
    return [
        'id' => 7,
        'form_key' => 'contact',
        'name' => 'Contact',
        'description' => 'Reach the team.',
        'parent_form_id' => 0,
        'status' => 'published',
        'is_template' => 0,
        'default_template' => 0,
        'logged_in' => 0,
        'editable' => 0,
        'created_at' => '2026-01-01 00:00:00',
        'options' => ['submit_value' => 'Send', 'success_action' => 'message'],
        'actions' => [formidableApiAction()],
        'fields' => [
            [
                'id' => 1,
                'form_id' => 7,
                'field_key' => 'name',
                'name' => 'Your name',
                'description' => '',
                'type' => 'text',
                'default_value' => '',
                'field_order' => 0,
                'required' => 1,
                'options' => ['blank' => 'This field is required.'],
                'field_options' => [],
            ],
            [
                'id' => 2,
                'form_id' => 7,
                'field_key' => 'reason',
                'name' => 'Reason for contact',
                'description' => '',
                'type' => 'select',
                'default_value' => '',
                'field_order' => 1,
                'required' => 0,
                'options' => ['Sales', 'Support'],
                'field_options' => conditionalFieldOptions(),
            ],
        ],
    ];
}

/**
 * The loader configured to read from the migration API, with the given forms faked.
 *
 * @param  list<array<string, mixed>>  $forms
 */
function formidableApiLoader(array $forms): FormidableFormsLoader
{
    Http::fake([
        'https://example.test/*' => Http::response($forms, 200, ['X-WP-Total' => (string) \count($forms)]),
    ]);

    $loader = new FormidableFormsLoader;
    $loader->setMigrationApiSource(new Client('https://example.test', 'shhh'));

    return $loader;
}

/**
 * Load the given forms through the API path and return them as a typed list.
 *
 * The loader returns `array<int|string, mixed>|null`; the tests control the fixture, so the
 * shape is known and narrowed here in one place rather than at every access.
 *
 * @param  list<array<string, mixed>>  $forms
 * @param  ?BufferedOutput  $output  A buffer to capture the command's output in, when a test reads it
 * @return list<array<string, mixed>> The translated forms
 */
function loadFormidableApiForms(array $forms, ?BufferedOutput $output = null): array
{
    $loaded = formidableApiLoader($forms)->load('', formidableLoaderCommand($output));

    expect($loaded)->not->toBeNull();

    /** @var list<array<string, mixed>> $loaded */
    return $loaded;
}

/**
 * The field at the given position, narrowed to an array for the type checker.
 *
 * @param  list<array<string, mixed>>  $forms
 * @return array<string, mixed> The field
 */
function apiField(array $forms, int $formIndex, int $fieldIndex): array
{
    /** @var array<int, array<string, mixed>> $fields */
    $fields = $forms[$formIndex]['fields'];

    return $fields[$fieldIndex];
}

/**
 * A member asserted and returned as a string, so a json_decode of it is well-typed.
 *
 * @param  array<string, mixed>  $data
 * @return string The member value
 */
function apiStringMember(array $data, string $member): string
{
    expect($data[$member])->toBeString();

    /** @var string */
    return $data[$member];
}

/**
 * A member asserted and returned as an int, so it can key an int-keyed array.
 *
 * @param  array<string, mixed>  $data
 * @return int The member value
 */
function apiIntMember(array $data, string $member): int
{
    expect($data[$member])->toBeInt();

    /** @var int */
    return $data[$member];
}

it('serializes field_options so conditional logic survives an API-sourced field', function (): void {
    // Against the UNFIXED loader this returns forms whose field_options are parsed arrays,
    // and extractConditionalLogicData() returns null for the conditional field -- a green
    // load that silently dropped the logic. The translation is what makes it survive.
    $forms = loadFormidableApiForms([formidableApiForm()]);

    expect($forms)->toHaveCount(1);
    $conditionalField = apiField($forms, 0, 1);

    // The mapping's extractConditionalLogicData() guard is: is_string(field_options),
    // then json_decode, then hasConditionalLogic. Assert the precondition it requires --
    // a string that decodes to the conditional structure -- which is exactly what returns
    // null (logic dropped) against the unfixed loader that leaves it a parsed array.
    $decoded = json_decode(apiStringMember($conditionalField, 'field_options'), true);
    expect($decoded)->toBeArray();
    /** @var array<string, mixed> $decoded */
    expect($decoded['hide_field'])->toBe(['2']);
});

it('serializes the form-level options so parseFormData does not skip them', function (): void {
    $forms = loadFormidableApiForms([formidableApiForm()]);

    // A parsed options array would be skipped by the is_string() guard in parseFormData().
    expect(json_decode(apiStringMember($forms[0], 'options'), true))
        ->toBe(['submit_value' => 'Send', 'success_action' => 'message']);
});

it('leaves a field with no conditional logic as an empty, still-string field_options', function (): void {
    $forms = loadFormidableApiForms([formidableApiForm()]);

    // The first field carries field_options => [] from the endpoint. It must still arrive as
    // a string, or isFieldOptionNotSet() reports every attribute unset.
    $plainField = apiField($forms, 0, 0);
    // An empty structure decodes to [], which hasConditionalLogic() rejects -- so the guard
    // correctly finds no logic, rather than skipping because the value was the wrong type.
    expect(json_decode(apiStringMember($plainField, 'field_options'), true))->toBe([]);
});

it('round-trips a member the endpoint already serves as a string, unchanged', function (): void {
    // Idempotence: a future endpoint that serialized options itself must not be double-encoded.
    $form = formidableApiForm();
    $form['options'] = '{"submit_value":"Send"}';

    /** @var array<int, array<string, mixed>> $fields */
    $fields = $form['fields'];
    $fields[1]['field_options'] = '{"show_hide":"show"}';
    $form['fields'] = $fields;

    $forms = loadFormidableApiForms([$form]);

    expect($forms[0]['options'])->toBe('{"submit_value":"Send"}');
    expect(apiField($forms, 0, 1)['field_options'])->toBe('{"show_hide":"show"}');
});

it('does not translate for a plugin whose payload already matches its file', function (): void {
    // The base hook is identity; only Formidable overrides it. So it is exercised on a
    // GRAVITY loader, which inherits the base hook untouched -- a Gravity form with a parsed
    // options member passes straight through, proving the translation is Formidable's alone
    // and not applied to every plugin. Invoked on a Formidable loader this would assert the
    // override against itself and survive any change to the base hook.
    $loader = new GravityFormsLoader;
    $reflection = new ReflectionMethod($loader, 'translateApiForm');

    // A parsed options member is returned as it arrived, not serialized.
    $parsed = ['id' => 1, 'options' => ['submit_value' => 'Send']];
    expect($reflection->invoke($loader, $parsed, formidableLoaderCommand()))->toBe($parsed);
});

// --- actions[] -> views[] (#811, uamswp-migration-api-archive#284) --------------------
//
// The endpoint serves form actions as actions[]; the mapping consumes views[]. The
// translation reproduces the XML loader's view shape so both sourcing paths hand the
// mapping identical views -- the parity wordpress-importer-archive#811 is about, not an improvement over the file.

it('translates actions[] into the views[] the mapping consumes', function (): void {
    $forms = loadFormidableApiForms([formidableApiForm()]);

    // actions[] is consumed by the translation and removed; views[] takes its place.
    expect($forms[0])->toHaveKey('views');
    expect($forms[0])->not->toHaveKey('actions');

    /** @var list<array<string, mixed>> $views */
    $views = $forms[0]['views'];
    expect($views)->toHaveCount(1);
    $view = $views[0];

    // The keys the XML loader produces, so the two paths agree.
    expect($view['excerpt'])->toBe('on_submit');
    expect($view['title'])->toBe('Confirmation');
    expect($view['post_name'])->toBe('7_on_submit_1');
    expect($view['status'])->toBe('publish');
    expect($view['post_id'])->toBe(900);
});

it('re-serializes an action settings map to a content string the mapping decodes', function (): void {
    $forms = loadFormidableApiForms([formidableApiForm()]);
    /** @var list<array<string, mixed>> $views */
    $views = $forms[0]['views'];

    // content must be a string, because the mapping json_decodes view['content'] behind an
    // is_string() guard -- a parsed settings map would be silently skipped.
    $content = apiStringMember($views[0], 'content');
    expect(json_decode($content, true))->toBe(['success_action' => 'message', 'success_msg' => 'Thanks.']);
});

it('emits the action name as post_name, not name, so the title fallback does not fire on the slug', function (): void {
    // The endpoint's actions[].name is the post_name slug (1_..._1). The mapping's title
    // resolution reads view['name'] as a fallback; passing the slug through as name would
    // surface it as the view title. Emitting it as post_name -- which no arm reads --
    // matches the export and keeps the paths in agreement.
    $forms = loadFormidableApiForms([formidableApiForm()]);
    /** @var list<array<string, mixed>> $views */
    $views = $forms[0]['views'];

    expect($views[0])->not->toHaveKey('name');
    expect($views[0]['post_name'])->toBe('7_on_submit_1');
});

it('seeds an empty views list for a form the endpoint serves no actions for', function (): void {
    $form = formidableApiForm();
    unset($form['actions']);

    $forms = loadFormidableApiForms([$form]);

    // The XML loader seeds `views => []` on every form; the API path matches it, so the two
    // sources hand the mapping the same keys and not merely the same behavior.
    expect($forms[0]['views'])->toBe([]);
    expect($forms[0])->not->toHaveKey('actions');
});

it('passes an action settings member the endpoint already serialized through as content', function (): void {
    $form = formidableApiForm();
    $form['actions'] = [formidableApiAction(['settings' => '{"success_msg":"Already a string."}'])];

    $forms = loadFormidableApiForms([$form]);

    /** @var array<int, array<string, mixed>> $views */
    $views = $forms[0]['views'];

    // The same idempotence the field members have: a pre-serialized value is not dropped.
    expect($views[0]['content'])->toBe('{"success_msg":"Already a string."}');
});

// --- criterion 4: what the endpoint withheld is reported, not dropped (wordpress-importer-archive#811) ---------
//
// An action's `denied` counters are the endpoint's own record of settings it refused to
// serve. A view translated without them imports green with those settings absent, and the
// mapping cannot tell a withheld setting from one that was never set -- so the seam reports.

it('reports the action settings the endpoint withheld', function (): void {
    $form = formidableApiForm();
    $form['actions'] = [formidableApiAction(['denied' => ['total' => 3, 'by_name' => 2, 'by_shape' => 1]])];

    $output = new BufferedOutput;
    loadFormidableApiForms([$form], $output);

    expect($output->fetch())->toContain('Formidable form 7: 3 action setting(s) withheld by the endpoint denylist');
});

it('stays quiet when the endpoint withheld nothing', function (): void {
    // The control: the fixture's action carries zeroed counters, so no warning is printed.
    $output = new BufferedOutput;
    loadFormidableApiForms([formidableApiForm()], $output);

    expect($output->fetch())->not->toContain('withheld by the endpoint denylist');
});

// --- criterion 5: same-form sourcing equivalence on the committed fixtures (wordpress-importer-archive#811) ---
//
// The two fixtures under tests/Fixtures/form-sourcing/formidable are the SAME form (form
// `3`, key `l3220`) captured from both sources four minutes apart on 2026-09-15. Where the
// tests above use constructed payloads to pin the translation's mechanics, these assert the
// sourcing-parity claim itself, on real data: what the XML export hands the mapping, the
// translated API path reproduces.
//
// The assertion is ONE-DIRECTIONAL by construction, per the fixtures' README. `field_options`
// carries 17/14 members from the export and 67 from the API -- the endpoint hydrates the
// defaults the export omits (`hide_field_cond` => `["=="]` and the like). So only the
// XML -> API survival direction is a parity requirement; the members present solely in the
// API payload are expected hydration, never a disagreement. `field_options` is the member the
// whole slice exists to preserve: it holds every field's conditional logic, and the mapping's
// `is_string()` guards drop it silently when it arrives parsed.
//
// Form-level `options` and field-level `options` (choice labels) are deliberately NOT asserted
// equal here: the two sources capture different projections of the form settings, and the
// choice labels were scrubbed independently in each fixture -- both documented in the README.

/**
 * The directory holding the committed same-form fixtures.
 */
function formidableFixtureDir(): string
{
    return __DIR__.'/../Fixtures/form-sourcing/formidable';
}

/**
 * Form 3 as the XML export path hands it to the mapping.
 *
 * @return array<string, mixed>
 */
function xmlSourcedForm3(): array
{
    $forms = (new FormidableXmlFileLoader('FormidableForms'))
        ->load(formidableFixtureDir().'/xml-form3.xml', formidableLoaderCommand());

    expect($forms)->not->toBeNull();

    /** @var list<array<string, mixed>> $forms */
    return $forms[0];
}

/**
 * Form 3 as the translated migration-API path hands it to the mapping.
 *
 * The fixture is the endpoint's own list-of-one payload, loaded through the real `load()`
 * seam (HTTP faked) so the whole sourcing path is exercised, not just the translation hook.
 *
 * @return array<string, mixed>
 */
function apiSourcedForm3(): array
{
    /** @var list<array<string, mixed>> $list */
    $list = json_decode((string) file_get_contents(formidableFixtureDir().'/api-form3.json'), true);

    return loadFormidableApiForms($list)[0];
}

/**
 * Decode each field's `field_options`, keyed by field id.
 *
 * `apiStringMember` asserts the member is a string first -- the load-bearing property both
 * paths must satisfy -- so a parsed `field_options` from either source fails here rather than
 * decoding to null and passing vacuously.
 *
 * @param  array<string, mixed>  $form
 * @return array<int, array<string, mixed>> field id => decoded field_options
 */
function decodedFieldOptionsById(array $form): array
{
    /** @var array<int, array<string, mixed>> $fields */
    $fields = $form['fields'];

    $out = [];
    foreach ($fields as $field) {
        $decoded = json_decode(apiStringMember($field, 'field_options'), true);
        expect($decoded)->toBeArray();

        /** @var array<string, mixed> $decoded */
        $out[apiIntMember($field, 'id')] = $decoded;
    }

    return $out;
}

it('hands the mapping a string field_options for every field, from both sources', function (): void {
    // The whole wordpress-importer-archive#811 field fix is that both paths deliver a STRING field_options -- the shape
    // the mapping's is_string() guards accept. A parsed value imports green and drops the logic.
    foreach (['xml' => xmlSourcedForm3(), 'api' => apiSourcedForm3()] as $form) {
        /** @var array<int, array<string, mixed>> $fields */
        $fields = $form['fields'];
        expect($fields)->toHaveCount(2);
        foreach ($fields as $field) {
            expect($field['field_options'])->toBeString();
        }
    }
});

it('reproduces every XML field_options member from the API for the same real form', function (): void {
    $xml = decodedFieldOptionsById(xmlSourcedForm3());
    $api = decodedFieldOptionsById(apiSourcedForm3());

    // Both sources describe the same two fields.
    expect(array_keys($xml))->toEqualCanonicalizing([287, 598]);

    // One-directional: every member the export carries survives translation with a matching
    // value. Members the API adds are expected hydration and are not asserted against.
    foreach ($xml as $fieldId => $xmlOptions) {
        expect($api)->toHaveKey($fieldId);
        foreach ($xmlOptions as $member => $value) {
            expect($api[$fieldId])->toHaveKey($member);
            expect($api[$fieldId][$member])->toBe($value);
        }
    }
});

it('carries field 287 conditional logic identically from both sources', function (): void {
    // Field 287's real conditional logic: reveal a follow-up field when "Other" is chosen. It
    // is the concrete thing an untranslated (parsed) field_options would drop silently, so pin
    // it directly rather than relying only on the whole-member sweep above.
    $xml = decodedFieldOptionsById(xmlSourcedForm3())[287];
    $api = decodedFieldOptionsById(apiSourcedForm3())[287];

    expect($xml['hide_field'])->toBe(['275']);
    expect($api['hide_field'])->toBe(['275']);
    expect($xml['hide_opt'])->toBe(['Other']);
    expect($api['hide_opt'])->toBe(['Other']);

    // Field 598 carries no hide_* logic in either source -- the fixtures pair a field with
    // conditional logic against one without, so a translation that dropped logic would show
    // up as 287 diverging while 598 stayed green.
    $xml598 = decodedFieldOptionsById(xmlSourcedForm3())[598];
    expect($xml598)->not->toHaveKey('hide_field');
});

it('resolves the same view title from the API and from the export file (wordpress-importer-archive#1121)', function (): void {
    // API path: actions[] translated to views[] by the loader.
    $apiForms = loadFormidableApiForms([formidableApiForm()]);
    /** @var list<array<string, mixed>> $apiViews */
    $apiViews = $apiForms[0]['views'];

    // File path: the same action, as the XML loader reads it from an export.
    $loader = new FormidableXmlFileLoader('FormidableForms');
    $convert = new ReflectionMethod($loader, 'convertXmlToArray');
    $convert->setAccessible(true);
    $xmlForms = $convert->invoke($loader, new SimpleXMLElement(
        '<?xml version="1.0" encoding="UTF-8"?><formidable>'
        .'<form><id>7</id><name>Contact</name><field><id>70</id><type>text</type></field></form>'
        .'<view><title>Confirmation</title><content><![CDATA[{}]]></content><excerpt><![CDATA[on_submit]]></excerpt>'
        .'<post_id>900</post_id><post_name>7_on_submit_1</post_name><status>publish</status>'
        .'<menu_order>7</menu_order><post_type>frm_form_actions</post_type></view>'
        .'</formidable>'
    ));
    expect($xmlForms)->toBeArray();
    /** @var list<array<string, mixed>> $xmlForms */
    $xmlViews = $xmlForms[0]['views'] ?? [];
    expect($xmlViews)->toBeArray()->toHaveCount(1);
    /** @var list<array<string, mixed>> $xmlViews */
    $apiTitle = FormConfigurationManager::resolveViewTitle($apiViews[0], 'Default');
    $xmlTitle = FormConfigurationManager::resolveViewTitle($xmlViews[0], 'Default');

    // The action's name, on both paths, not its type.
    expect($apiTitle)->toBe('Confirmation');
    expect($xmlTitle)->toBe($apiTitle);
});
