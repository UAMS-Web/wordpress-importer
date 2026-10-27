<?php

declare(strict_types=1);

/**
 * Tests that `FieldMapper` turns a WordPress item's postmeta into the Statamic fields
 * the entry creators save: hero slides gathered from their flat keys, the direct
 * maps, each special-case key, and the landing-page header default, along with
 * which keys it reports as mapped.
 *
 * The asset importer is mocked to return `images/<id>.jpg` for every image it is
 * asked for, so each image field shows which attachment id was looked up. For a
 * site other than `default` it returns `images/<site>/<id>.jpg`, so a field also
 * shows which site it was imported for; `missing` imports nothing (`null`) and
 * `gone` imports an empty path, as an attachment whose file has left the library.
 *
 * @command  php vendor/bin/pest tests/Integration/FieldMapperTest.php
 */

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FieldMapper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Map the given postmeta and return the data it produced and the keys it reported.
 *
 * @param  array<int|string, mixed>  $meta
 * @param  array<int|string, mixed>  $data
 * @param  array<int|string, mixed>  $attachments
 * @return array{0: array<int|string, mixed>, 1: array<int|string, mixed>}
 */
function fieldMapperRun(
    TestCase $test,
    array $meta,
    array $data = ['site' => 'default'],
    string $postType = 'page',
    array $attachments = []
): array {
    $importer = (new ReflectionMethod($test, 'createMock'))->invoke($test, AssetImporter::class);

    if (! $importer instanceof AssetImporter || ! $importer instanceof MockObject) {
        throw new RuntimeException('Could not mock the asset importer.');
    }

    $importer->method('getUploadImages')->willReturn(true);
    $importer->method('importImage')->willReturnCallback(
        static fn (string $id, string $site): ?string => match ($id) {
            '', 'missing' => null,
            'gone' => '',
            default => $site === 'default' ? "images/{$id}.jpg" : "images/{$site}/{$id}.jpg",
        }
    );

    $keys = (new FieldMapper)->mapFields($meta, $data, $postType, app(XmlParser::class), $importer, $attachments, new BufferedOutput);

    return [$data, $keys];
}

/**
 * A hero slide as mapped, without its generated row id.
 *
 * @param  array<int|string, mixed>  $data
 * @return array<int|string, mixed>
 */
function fieldMapperSlide(array $data, int $index): array
{
    $heroes = is_array($data['header_hero'] ?? null) ? $data['header_hero'] : [];
    $slide = is_array($heroes[$index] ?? null) ? $heroes[$index] : [];
    unset($slide['id']);

    return $slide;
}

it('gathers each hero slide from its flat meta keys', function (): void {
    $meta = [
        'page_hero_hero_0_hero_heading' => '<b>Big</b> news',
        'page_hero_hero_0_hero_body' => '<p>Body</p>',
        'page_hero_hero_0_hero_button_text' => '<i>Go</i>',
        'page_hero_hero_0_hero_button_description' => '<i>Describe</i>',
        'page_hero_hero_0_hero_disable' => '1',
        'page_hero_hero_0_hero_background_color' => 'blue',
        'page_hero_hero_0_hero_image_desktop' => '11',
        'page_hero_hero_0_hero_image_tablet' => '',
        'page_hero_hero_0_hero_image_mobile' => '',
        'page_hero_hero_0_hero_image_alt_text' => '',
        'page_hero_hero_1_hero_disable' => '0',
        'page_hero_hero_1_hero_image_desktop' => '',
        'page_hero_hero_1_hero_image_tablet' => '12',
        'page_hero_hero_1_hero_image_mobile' => '13',
        'page_hero_hero_1_hero_image_alt_text' => '<i>Alt</i>',
        'page_hero_hero_1_hero_unknown_field' => 'ignored',
    ];

    [$data, $keys] = fieldMapperRun($this, $meta);

    expect(fieldMapperSlide($data, 0))->toBe([
        'type' => 'new_set',
        'enabled' => false,
        'heading' => 'Big news',
        'body' => 'Body',
        'button_text' => 'Go',
        'button_link_description' => 'Describe',
        'background_color' => 'blue',
        'image_desktop' => 'images/11.jpg',
    ])
        ->and(fieldMapperSlide($data, 1))->toBe([
            'type' => 'new_set',
            'enabled' => true,
            'image_desktop' => null,
            'image_tablet' => 'images/12.jpg',
            'image_mobile' => 'images/13.jpg',
            'image_alt_text' => 'Alt',
        ])
        ->and($keys)->toBe(array_keys($meta));

    // Each slide gets its own generated row id
    $heroes = is_array($data['header_hero'] ?? null) ? $data['header_hero'] : [];
    $ids = array_map(
        static fn (mixed $slide): string => is_array($slide) && is_string($slide['id'] ?? null) ? $slide['id'] : '',
        $heroes
    );

    expect($ids)->not->toContain('')
        ->and(array_unique($ids))->toHaveCount(2);
});

it('maps a hero button link to its url and target', function (): void {
    // Characterization of #150: a `_blank` target imports as false
    [$data] = fieldMapperRun($this, ['page_hero_hero_0_hero_button_url' => ['url' => 'https://example.com/apply', 'target' => '_blank']]);

    $slide = fieldMapperSlide($data, 0);

    expect($slide['button_url'] ?? null)->toBe('https://example.com/apply')
        ->and($slide['button_target'] ?? null)->toBeFalse();
});

it('skips hero slides when the entry has no site', function (): void {
    [$data, $keys] = fieldMapperRun($this, ['page_hero_hero_0_hero_heading' => 'Hi'], []);

    expect($data)->not->toHaveKey('header_hero')
        ->and($keys)->toBe(['page_hero_hero_0_hero_heading']);
});

it('passes over meta keys that are not strings', function (): void {
    [$data, $keys] = fieldMapperRun($this, [5 => '1']);

    expect($data)->toBe(['site' => 'default'])
        ->and($keys)->toBe([]);
});

it('skips an ACF field reference whose name matches the key it follows', function (): void {
    // The check compares the key without `_` to the value without its first seven
    // characters; `field_` is six, so a reference spelled with one extra character
    // matches, and the theme's real references do not (#148)
    [$data, $keys] = fieldMapperRun($this, ['_page_subsection' => 'field_Xpage_subsection']);

    expect($data)->not->toHaveKey('subsection')
        ->and($keys)->toBe(['_page_subsection']);
});

it('lets the theme\'s ACF reference row overwrite post_hide_author', function (): void {
    // Characterization of #148: `_post_hide_author` holds `field_post_hide_author`
    [$data] = fieldMapperRun($this, ['post_hide_author' => '1', '_post_hide_author' => 'field_post_hide_author']);

    expect($data['hide_creator_query'] ?? null)->toBeFalse();
});

it('maps the direct keys, with or without a leading underscore', function (): void {
    [$data, $keys] = fieldMapperRun($this, [
        'genesis_scripts' => '<script>head</script>',
        '_genesis_scripts_body' => '<script>body</script>',
        'genesis_scripts_body_position' => 'top',
        '_seopress_robots_canonical_meta' => 'https://example.com/canonical',
    ]);

    expect($data)->toBe([
        'site' => 'default',
        'scripts_head' => ['code' => '<script>head</script>', 'mode' => 'htmlmixed'],
        'scripts_body' => ['code' => '<script>body</script>', 'mode' => 'htmlmixed'],
        'scripts_body_position' => 'top',
        'robots_canonical_meta' => 'https://example.com/canonical',
    ])
        ->and($keys)->toBe(['genesis_scripts', '_genesis_scripts_body', 'genesis_scripts_body_position', '_seopress_robots_canonical_meta']);
});

it('stores an absent script as empty code', function (): void {
    [$data] = fieldMapperRun($this, ['genesis_scripts' => null]);

    expect($data['scripts_head'] ?? null)->toBe(['code' => '', 'mode' => 'htmlmixed']);
});

it('reads a WordPress 1 as true and anything else as false', function (string $key, string $field): void {
    [$on] = fieldMapperRun($this, [$key => '1']);
    [$off] = fieldMapperRun($this, [$key => '0']);
    [$absent] = fieldMapperRun($this, [$key => null]);

    expect($on[$field] ?? null)->toBeTrue()
        ->and($off[$field] ?? null)->toBeFalse()
        ->and($absent[$field] ?? null)->toBeFalse();
})->with([
    'post_hide_author' => ['post_hide_author', 'hide_creator_query'],
    '_post_hide_author' => ['_post_hide_author', 'hide_creator_query'],
    'seopress robots index' => ['seopress_robots_index_meta', 'robots_index_meta'],
    'seopress robots follow' => ['_seopress_robots_follow_meta', 'robots_follow_meta'],
    'seopress robots odp' => ['seopress_robots_odp_meta', 'robots_odp_meta'],
    'seopress robots image index' => ['_seopress_robots_imageindex_meta', 'robots_imageindex_meta'],
    'seopress robots archive' => ['seopress_robots_archive_meta', 'robots_archive_meta'],
    'seopress robots snippet' => ['_seopress_robots_snippet_meta', 'robots_snippet_meta'],
    'page_subsection' => ['page_subsection', 'subsection'],
    'page_hide_child_menu' => ['page_hide_child_menu', 'hide_child_menu'],
    'include_boilerplate' => ['include_boilerplate', 'boilerplate_query'],
    'page_hide_from_menu' => ['page_hide_from_menu', 'hide_from_menu'],
    'page_hide_breadcrumbs' => ['page_hide_breadcrumbs', 'hide_breadcrumbs'],
]);

it('reports each special-case key as mapped', function (string $key): void {
    [, $keys] = fieldMapperRun($this, [$key => 'x']);

    expect($keys)->toBe([$key]);
})->with([
    'post_hide_author', '_seopress_robots_index_meta', 'page_title_options', 'page_subsection', 'page_nav_alt_name',
    'page_hide_from_menu', 'parent', 'page_hide_child_menu', 'page_hide_breadcrumbs', 'breadcrumb',
    'include_boilerplate', 'media_contact', 'news_release_pdf_url', 'news_release_pdf', 'page_description',
    'page_cover_image', '_wp_page_template', '_thumbnail_id', 'page_landing_page_heading',
    'page_landing_page_description', 'page_landing_page_cover_image', 'page_landing_page_cover_image_mobile',
]);

it('maps title options to their Statamic values', function (string $value, string $option): void {
    [$data] = fieldMapperRun($this, ['page_title_options' => $value]);

    expect($data['title_options'] ?? null)->toBe($option);
})->with([
    ['none', 'normal'],
    ['hidden', 'hidden'],
    ['graphic', 'graphic'],
    ['landingpage', 'marketing-landing-page'],
    ['hero', 'hero'],
    ['something else', 'normal'],
]);

it('strips markup from the navigation name', function (): void {
    [$data] = fieldMapperRun($this, ['page_nav_alt_name' => '<b>Short</b> name']);

    expect($data['nav_alt_name'] ?? null)->toBe('Short name');
});

it('reads a number as its string', function (): void {
    [$data] = fieldMapperRun($this, ['page_nav_alt_name' => 5]);

    expect($data['nav_alt_name'] ?? null)->toBe('5');
});

it('reads an array as an empty string', function (): void {
    [$data] = fieldMapperRun($this, ['page_nav_alt_name' => ['not', 'scalar']]);

    expect($data['nav_alt_name'] ?? null)->toBe('');
});

it('hides a page from menus once parent is on, whatever order the keys come in', function (array $meta, ?bool $hidden): void {
    [$data] = fieldMapperRun($this, $meta);

    expect($data['hide_from_menu'] ?? null)->toBe($hidden);
})->with([
    'parent on, then not hidden' => [['parent' => 'on', 'page_hide_from_menu' => '0'], true],
    'not hidden, then parent on' => [['page_hide_from_menu' => '0', 'parent' => 'on'], true],
    'parent off alone' => [['parent' => 'off'], null],
]);

it('hides breadcrumbs once breadcrumb is on, whatever order the keys come in', function (array $meta, ?bool $hidden): void {
    [$data] = fieldMapperRun($this, $meta);

    expect($data['hide_breadcrumbs'] ?? null)->toBe($hidden);
})->with([
    'breadcrumb on, then not hidden' => [['breadcrumb' => 'on', 'page_hide_breadcrumbs' => '0'], true],
    'not hidden, then breadcrumb on' => [['page_hide_breadcrumbs' => '0', 'breadcrumb' => 'on'], true],
    'breadcrumb off alone' => [['breadcrumb' => 'off'], null],
]);

it('maps a media contact to its fieldset key, and ignores an empty one', function (string $value, ?string $contact): void {
    [$data] = fieldMapperRun($this, ['media_contact' => $value]);

    expect($data['media_contact'] ?? null)->toBe($contact);
})->with([
    'a Media name' => ['Media Relations', 'relations'],
    'a name with spaced prefix' => ['Media   Team', 'team'],
    'a name without the prefix' => ['Press Office', 'press office'],
    'empty' => ['', null],
    'the string null' => ['null', null],
]);

it('maps the page description and cover image into the header graphic, keeping what is there', function (): void {
    [$data] = fieldMapperRun($this, ['page_description' => '<p>About us</p>', 'page_cover_image' => '21'], ['site' => 'default', 'header_graphic' => ['kept' => true]]);
    [$empty] = fieldMapperRun($this, ['page_description' => '']);

    expect($data['header_graphic'] ?? null)->toBe(['kept' => true, 'body' => 'About us', 'image' => 'images/21.jpg'])
        ->and($empty['header_graphic'] ?? null)->toBe(['body' => null]);
});

it('switches to the landing page blueprint for the landing templates only', function (string $template, bool $landing): void {
    [$data] = fieldMapperRun($this, ['_wp_page_template' => $template]);

    expect($data['blueprint'] ?? null)->toBe($landing ? 'landing_page' : null)
        ->and($data['template'] ?? null)->toBe($landing ? 'pages/page_landing' : null)
        ->and($data['header_marketing_landing_page'] ?? null)->toBe(
            $landing ? ['heading' => null, 'body' => null, 'image' => null, 'image_mobile' => null] : null
        );
})->with([
    'modules' => ['templates/modules.php', true],
    'landing' => ['templates/page_landing.php', true],
    'marketing' => ['templates/marketing.php', true],
    'default' => ['default', false],
    'empty' => ['', false],
    'another template' => ['templates/other.php', false],
]);

it('keeps a landing header already mapped instead of the default', function (): void {
    [$data] = fieldMapperRun($this, [
        'page_landing_page_heading' => '<b>Welcome</b>',
        'page_landing_page_description' => '<p>Intro</p>',
        'page_landing_page_cover_image' => '41',
        'page_landing_page_cover_image_mobile' => '42',
        '_wp_page_template' => 'templates/page_landing.php',
    ], ['site' => 'default', 'header_marketing_landing_page' => ['kept' => true]]);

    expect($data['header_marketing_landing_page'] ?? null)->toBe([
        'kept' => true,
        'heading' => 'Welcome',
        'body' => 'Intro',
        'image' => 'images/41.jpg',
        'image_mobile' => 'images/42.jpg',
    ]);
});

it('leaves empty landing fields null and imports no empty mobile image', function (): void {
    [$data] = fieldMapperRun($this, [
        'page_landing_page_heading' => '',
        'page_landing_page_description' => '',
        'page_landing_page_cover_image_mobile' => '',
    ]);

    expect($data['header_marketing_landing_page'] ?? null)->toBe(['heading' => null, 'body' => null]);
});

it('imports the featured image of a page, and flags a credit only when the attachment has one', function (): void {
    $credited = simplexml_load_string(
        '<item xmlns:wp="http://wordpress.org/export/1.2/"><title>Img</title><wp:post_id>31</wp:post_id>'
        .'<wp:attachment_url>https://example.com/a.jpg</wp:attachment_url>'
        .'<wp:postmeta><wp:meta_key>_media_credit</wp:meta_key><wp:meta_value>Jane Doe</wp:meta_value></wp:postmeta></item>'
    );

    [$withCredit] = fieldMapperRun($this, ['_thumbnail_id' => '31'], attachments: [$credited]);
    [$withoutCredit] = fieldMapperRun($this, ['_thumbnail_id' => '32']);

    expect($withCredit['featured_image_16_9'] ?? null)->toBe('images/31.jpg')
        ->and($withCredit['featured_image_credit_query'] ?? null)->toBeTrue()
        ->and($withoutCredit['featured_image_16_9'] ?? null)->toBe('images/32.jpg')
        ->and($withoutCredit)->not->toHaveKey('featured_image_credit_query');
});

it('imports no featured image for a post or an empty thumbnail', function (string $postType, string $thumbnail): void {
    [$data] = fieldMapperRun($this, ['_thumbnail_id' => $thumbnail], postType: $postType);

    expect($data)->not->toHaveKey('featured_image_16_9');
})->with([
    'a post' => ['post', '31'],
    'an empty thumbnail' => ['page', ''],
]);

it('keeps reading meta after a key that is not a string', function (): void {
    [$data, $keys] = fieldMapperRun($this, [
        5 => '1',
        'page_hero_hero_0_hero_heading' => 'Hi',
        'post_hide_author' => '1',
    ]);

    expect(fieldMapperSlide($data, 0)['heading'] ?? null)->toBe('Hi')
        ->and($data['hide_creator_query'] ?? null)->toBeTrue()
        ->and($keys)->toBe(['page_hero_hero_0_hero_heading', 'post_hide_author']);
});

it('keeps reading meta after a hero key and after an ACF reference', function (): void {
    [$data, $keys] = fieldMapperRun($this, [
        'page_hero_hero_0_hero_heading' => 'Hi',
        '_page_subsection' => 'field_Xpage_subsection',
        'post_hide_author' => '1',
    ]);

    expect($data['hide_creator_query'] ?? null)->toBeTrue()
        ->and($keys)->toBe(['page_hero_hero_0_hero_heading', '_page_subsection', 'post_hide_author']);
});

it('skips an ACF reference only when its key starts with an underscore', function (): void {
    // The value and the name match, so only the missing `_` keeps this row from
    // being read as a reference
    [, $keys] = fieldMapperRun($this, ['Xpage_subsection' => 'field_Xpage_subsection']);

    expect($keys)->toBe([]);
});

it('skips an ACF reference only when its value starts with field_', function (): void {
    // The name matches once seven characters are dropped, so only the prefix keeps
    // this row from being read as a reference
    [, $keys] = fieldMapperRun($this, ['_Xpage_unmapped' => '1234567Xpage_unmapped']);

    expect($keys)->toBe([]);
});

it('imports every image for the entry\'s own site', function (): void {
    $meta = [
        'page_hero_hero_0_hero_image_desktop' => '11',
        'page_cover_image' => '21',
        '_thumbnail_id' => '31',
        'page_landing_page_cover_image' => '41',
        'page_landing_page_cover_image_mobile' => '42',
    ];

    [$data] = fieldMapperRun($this, $meta, ['site' => 'other']);

    $graphic = is_array($data['header_graphic'] ?? null) ? $data['header_graphic'] : [];
    $landing = is_array($data['header_marketing_landing_page'] ?? null) ? $data['header_marketing_landing_page'] : [];

    expect(fieldMapperSlide($data, 0)['image_desktop'] ?? null)->toBe('images/other/11.jpg')
        ->and($graphic['image'] ?? null)->toBe('images/other/21.jpg')
        ->and($data['featured_image_16_9'] ?? null)->toBe('images/other/31.jpg')
        ->and($landing['image'] ?? null)->toBe('images/other/41.jpg')
        ->and($landing['image_mobile'] ?? null)->toBe('images/other/42.jpg');
});

it('imports images with no site when the entry has none', function (): void {
    // An entry without a site still maps its cover images, looked up with an empty
    // site, which the mock answers as a site other than `default`
    $meta = [
        'page_cover_image' => '21',
        '_thumbnail_id' => '31',
        'page_landing_page_cover_image' => '41',
        'page_landing_page_cover_image_mobile' => '42',
    ];

    [$data] = fieldMapperRun($this, $meta, []);

    $graphic = is_array($data['header_graphic'] ?? null) ? $data['header_graphic'] : [];
    $landing = is_array($data['header_marketing_landing_page'] ?? null) ? $data['header_marketing_landing_page'] : [];

    expect($graphic['image'] ?? null)->toBe('images//21.jpg')
        ->and($data['featured_image_16_9'] ?? null)->toBe('images//31.jpg')
        ->and($landing['image'] ?? null)->toBe('images//41.jpg')
        ->and($landing['image_mobile'] ?? null)->toBe('images//42.jpg');
});

it('sets no featured image or credit when a credited image imports nothing', function (string $thumbnail): void {
    $credited = simplexml_load_string(
        '<item xmlns:wp="http://wordpress.org/export/1.2/"><title>Img</title><wp:post_id>'.$thumbnail.'</wp:post_id>'
        .'<wp:attachment_url>https://example.com/a.jpg</wp:attachment_url>'
        .'<wp:postmeta><wp:meta_key>_media_credit</wp:meta_key><wp:meta_value>Jane Doe</wp:meta_value></wp:postmeta></item>'
    );

    [$data, $keys] = fieldMapperRun($this, ['_thumbnail_id' => $thumbnail], attachments: [$credited]);

    expect($data)->not->toHaveKey('featured_image_16_9')
        ->and($data)->not->toHaveKey('featured_image_credit_query')
        ->and($keys)->toBe(['_thumbnail_id']);
})->with([
    'a null path' => ['missing'],
    'an empty path' => ['gone'],
]);

it('enables a hero slide that carries no disable key', function (): void {
    [$data] = fieldMapperRun($this, ['page_hero_hero_0_hero_heading' => 'Hi']);

    expect(fieldMapperSlide($data, 0)['enabled'] ?? null)->toBeTrue();
});

it('gathers a zero-padded hero index into the same slide', function (): void {
    [$data] = fieldMapperRun($this, ['page_hero_hero_01_hero_heading' => 'A', 'page_hero_hero_1_hero_body' => 'B']);

    $heroes = is_array($data['header_hero'] ?? null) ? $data['header_hero'] : [];

    expect(array_keys($heroes))->toBe([1])
        ->and(fieldMapperSlide($data, 1))->toBe(['type' => 'new_set', 'enabled' => true, 'heading' => 'A', 'body' => 'B']);
});

it('stores an empty media contact when the prefix replacement fails at a PCRE limit', function (): void {
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '0');

    try {
        [$data] = fieldMapperRun($this, ['media_contact' => 'Media   Team']);
    } finally {
        ini_set('pcre.backtrack_limit', $limit === false ? '1000000' : $limit);
    }

    expect($data['media_contact'] ?? null)->toBe('');
});

it('skips a direct-map target that is not a field handle, and keeps reading', function (): void {
    $mapper = new class extends FieldMapper
    {
        /**
         * A map whose first two targets are not field handles.
         *
         * @var array<int|string, mixed>
         */
        protected $directMapMetaKeys = ['bad_int' => 5, 'bad_empty' => '', 'genesis_scripts_body_position' => 'scripts_body_position'];
    };

    $data = ['site' => 'default'];
    $keys = $mapper->mapFields(['bad_int' => 'x', 'bad_empty' => 'y', 'genesis_scripts_body_position' => 'top'], $data, 'page', app(XmlParser::class), app(AssetImporter::class), [], new BufferedOutput);

    expect($data)->toBe(['site' => 'default', 'scripts_body_position' => 'top'])
        ->and($keys)->toBe(['genesis_scripts_body_position']);
});

it('skips a hero field key that is not a string, and keeps reading', function (): void {
    $data = [];

    (new FieldMapper)->processHeroFields([5 => 'x', 'heading' => 'Hi'], 0, $data, 'default', app(AssetImporter::class), [], new BufferedOutput);

    expect(fieldMapperSlide($data, 0))->toBe(['type' => 'new_set', 'enabled' => true, 'heading' => 'Hi']);
});
