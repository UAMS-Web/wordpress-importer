<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\AttachmentReferenceCollector;

/**
 * Every shape a scoped run's items can reach an attachment through (wordpress-importer-archive#1175).
 *
 * A scoped run reads only the attachments its items reference, so a shape the collector
 * misses is media that imports as nothing, with no error. **One case per shape, each built
 * so that removing that shape's extraction turns it red**: an item carries the shape and
 * nothing else that could yield the same id or path.
 *
 * The shapes, and the importer file that reads each one, are enumerated in
 * `docs/migration-api-contract.md` under the attachment references section.
 *
 * @command  php vendor/bin/pest tests/Unit/AttachmentReferenceCollectorTest.php
 */

/**
 * A collector for the test site, a subsite so the base path is exercised everywhere.
 */
function referenceCollector(): AttachmentReferenceCollector
{
    return new AttachmentReferenceCollector('https://example.test/dept');
}

/**
 * Collect one item carrying the given content and meta.
 *
 * @param  string  $content  The item's content
 * @param  array<string, list<string>>  $meta  Its meta rows
 */
function collectedFrom(
    string $content,
    array $meta = []
): AttachmentReferenceCollector {
    $collector = referenceCollector();
    $collector->collect(['id' => 42, 'content' => $content, 'meta' => $meta]);

    return $collector;
}

it('names _thumbnail_id and the other meta keys the importer reads an id out of', function (string $key): void {
    $collector = collectedFrom('', [$key => ['901']]);

    expect($collector->ids())->toBe([901])
        ->and($collector->namedIds())->toBe([901 => ['shape' => 'meta:'.$key, 'item' => 42]]);
})->with([
    '_thumbnail_id',
    'page_cover_image',
    'page_landing_page_cover_image',
    'page_landing_page_cover_image_mobile',
    'logo_image',
    'page_hero_hero_0_hero_image_desktop',
    'page_hero_hero_3_hero_image_mobile',
    'uamswp_modules_2_side_image_side_image_image',
]);

it('takes the ids out of a serialized KB attachment list', function (): void {
    $collector = collectedFrom('', [
        '_mkb_article_attachments' => ['a:2:{i:0;s:3:"501";i:1;s:3:"502";}'],
    ]);

    // The serialized array's own indices are collected too, as candidates; 501 and 502 are
    // what matters, and they are named.
    expect(\array_keys($collector->namedIds()))->toContain(501, 502)
        ->and($collector->namedIds()[501]['shape'])->toBe('meta:_mkb_article_attachments');
});

it('names the ids in a WP Adverts gallery order, a JSON list', function (): void {
    $collector = collectedFrom('', ['_adverts_attachments_order' => ['[511,512]']]);

    expect($collector->namedIds())->toBe([
        511 => ['shape' => 'meta:_adverts_attachments_order', 'item' => 42],
        512 => ['shape' => 'meta:_adverts_attachments_order', 'item' => 42],
    ]);
});

it('takes every integer in an unnamed meta value as a candidate without naming it', function (): void {
    // An ACF image field under a name the importer does not know by key.
    $collector = collectedFrom('', ['some_acf_image' => ['733']]);

    expect($collector->ids())->toBe([733])
        ->and($collector->namedIds())->toBe([]);
});

it('names core/image and core/gallery block attributes', function (): void {
    $collector = collectedFrom(
        '<!-- wp:image {"id":811,"sizeSlug":"large"} --><figure></figure><!-- /wp:image -->'
        .'<!-- wp:gallery {"ids":[812,813]} --><figure></figure><!-- /wp:gallery -->'
    );

    expect($collector->namedIds())->toMatchArray([
        811 => ['shape' => 'block:image:id', 'item' => 42],
        812 => ['shape' => 'block:gallery:ids', 'item' => 42],
        813 => ['shape' => 'block:gallery:ids', 'item' => 42],
    ]);
});

it('takes ACF block data ids as candidates', function (): void {
    $collector = collectedFrom(
        '<!-- wp:acf/uams-gallery {"name":"acf/uams-gallery","data":{"gallery_images":["821","822"],"_gallery_images":"field_5f"}} /-->'
    );

    expect($collector->ids())->toContain(821, 822);
});

it('does not name another block\'s id, which is a page or a menu', function (): void {
    $collector = collectedFrom('<!-- wp:navigation-link {"id":35,"kind":"post-type"} /-->');

    expect($collector->ids())->toContain(35)
        ->and($collector->namedIds())->toBe([]);
});

it('names a wp-image-N class', function (): void {
    $collector = collectedFrom('<img class="alignnone wp-image-831" src="/x.jpg">');

    expect($collector->namedIds())->toBe([831 => ['shape' => 'class:wp-image', 'item' => 42]]);
});

it('names data-id on an img and nowhere else', function (): void {
    $collector = collectedFrom('<figure><img data-id="841" src="/x.jpg"></figure><div data-id="842"></div>');

    expect($collector->namedIds())->toBe([841 => ['shape' => 'attribute:img-data-id', 'item' => 42]]);
});

it('names the gallery and caption shortcodes', function (): void {
    $collector = collectedFrom('[gallery columns="3" ids="851, 852"] [caption id="attachment_853" align="alignleft"]x[/caption]');

    expect($collector->namedIds())->toBe([
        853 => ['shape' => 'shortcode:caption', 'item' => 42],
        851 => ['shape' => 'shortcode:gallery', 'item' => 42],
        852 => ['shape' => 'shortcode:gallery', 'item' => 42],
    ]);
});

it('names an attachment_id query link', function (): void {
    $collector = collectedFrom('<a href="https://example.test/dept/?attachment_id=861">file</a>');

    expect($collector->namedIds())->toBe([861 => ['shape' => 'link:attachment_id', 'item' => 42]]);
});

it('takes ids out of a structured meta value, which the materializer writes as JSON', function (): void {
    $collector = referenceCollector();
    $collector->collect(['id' => 42, 'meta' => ['nested_gallery' => [['images' => [761, 762]]]]]);

    expect($collector->ids())->toContain(761, 762);
});

it('takes every integer when a block attribute is too large for the pattern', function (): void {
    // PCRE gives up on an attribute around a megabyte long, and without the fallback every
    // block in the item would go unread.
    $content = '<!-- wp:acf/cta {"data":{"cta_bar_image":"7777","blob":"'.\str_repeat('A', 1500000).'"}} /-->';

    expect(\preg_match_all('/<!--\s+wp:([a-z0-9\/_-]+)\s+(\{.*?\})\s*\/?-->/s', $content, $unused))->toBeFalse()
        ->and(collectedFrom($content)->ids())->toContain(7777);
});

it('ignores zero, which is WordPress for no attachment', function (): void {
    expect(collectedFrom('', ['_thumbnail_id' => ['0']])->ids())->toBe([]);
});

it('takes the uploads-relative path of every upload URL form', function (string $url, string $path): void {
    $collector = collectedFrom('<img src="'.$url.'">');

    expect($collector->paths())->toBe([$path]);
})->with([
    'uploads' => ['https://example.test/dept/wp-content/uploads/2020/09/a-750x629.png', '2020/09/a-750x629.png'],
    'uploads/sites/N' => ['https://example.test/dept/wp-content/uploads/sites/5/2020/09/b.jpg', '2020/09/b.jpg'],
    'blogs.dir' => ['https://example.test/dept/wp-content/blogs.dir/16/files/2020/08/c.jpg', '2020/08/c.jpg'],
    'files rewrite' => ['https://example.test/dept/files/2020/08/d.jpg', '2020/08/d.jpg'],
    'url-encoded' => ['https://example.test/dept/wp-content/uploads/2021/01/my%20photo.jpg', '2021/01/my photo.jpg'],
]);

it('drops the punctuation a URL written in prose ends with', function (): void {
    $collector = collectedFrom('See https://example.test/dept/wp-content/uploads/2020/09/k.jpg. Or https://example.test/dept/team/!');

    expect($collector->paths())->toBe(['2020/09/k.jpg'])
        ->and($collector->links())->toBe(['//example.test/dept/team' => 42]);
});

it('reads an upload URL out of JSON-escaped block attributes', function (): void {
    $collector = collectedFrom(
        '<!-- wp:acf/hero {"data":{"background":"https:\/\/example.test\/dept\/wp-content\/uploads\/2022\/02\/hero.jpg"}} /-->'
    );

    expect($collector->paths())->toBe(['2022/02/hero.jpg'])
        ->and(\array_keys($collector->imageUrls()))->toBe(['https://example.test/dept/wp-content/uploads/2022/02/hero.jpg']);
});

/**
 * In HTML an attribute value carries `&` as an entity, so a URL taken from markup is decoded
 * to the URL the markup holds, which is what the API serves and the importer reads (#269).
 */
it('decodes the HTML entities in a URL taken from markup', function (string $written): void {
    $collector = collectedFrom('<img src="https://example.test/dept/wp-content/uploads/2020/09/R'.$written.'D.jpg">');

    expect($collector->paths())->toBe(['2020/09/R&D.jpg'])
        ->and(\array_keys($collector->imageUrls()))->toBe(['https://example.test/dept/wp-content/uploads/2020/09/R&D.jpg']);
})->with([
    'named' => ['&amp;'],
    'zero-padded decimal' => ['&#038;'],
    'decimal' => ['&#38;'],
    'raw' => ['&'],
]);

it('decodes an apostrophe, which WordPress writes in an attribute as an entity', function (string $written): void {
    $collector = collectedFrom('<img src="https://example.test/dept/wp-content/uploads/2020/09/O'.$written.'Neil.jpg">');

    expect($collector->paths())->toBe(["2020/09/O'Neil.jpg"]);
})->with([
    'numeric' => ['&#039;'],
    'named' => ['&apos;'],
]);

/**
 * A URL quoted with an entity, as a shortcode inside markup is, ends at the closing quote,
 * and one followed by an entity-encoded tag ends before it (#309).
 *
 * @param  string  $content  The item's content
 */
it('ends a URL at an entity-encoded closing quote or tag', function (string $content): void {
    $collector = collectedFrom($content);

    expect($collector->paths())->toBe(['2020/09/a.jpg'])
        ->and(\array_keys($collector->imageUrls()))->toBe(['https://example.test/dept/wp-content/uploads/2020/09/a.jpg']);
})->with([
    'double-quoted, named' => ['[gallery link=&quot;https://example.test/dept/wp-content/uploads/2020/09/a.jpg&quot;]'],
    'double-quoted, numeric' => ['[gallery link=&#34;https://example.test/dept/wp-content/uploads/2020/09/a.jpg&#34;]'],
    'single-quoted, numeric' => ['[gallery link=&#039;https://example.test/dept/wp-content/uploads/2020/09/a.jpg&#039;]'],
    'single-quoted, named' => ['[gallery link=&apos;https://example.test/dept/wp-content/uploads/2020/09/a.jpg&apos;]'],
    'a tag after it' => ['https://example.test/dept/wp-content/uploads/2020/09/a.jpg&lt;br&gt;'],
    'a closing tag after it' => ['https://example.test/dept/wp-content/uploads/2020/09/a.jpg&lt;/p&gt;'],
]);

it('ends an entity-quoted link at its closing quote', function (): void {
    $collector = collectedFrom('[button url=&quot;https://example.test/dept/team/&quot;]');

    expect($collector->links())->toBe(['//example.test/dept/team' => 42]);
});

it('keeps an apostrophe in a file name when the URL is not quoted with one', function (string $content): void {
    $collector = collectedFrom($content);

    expect($collector->paths())->toBe(["2020/09/O'Neil.jpg"]);
})->with([
    'in an HTML attribute' => ['<img src="https://example.test/dept/wp-content/uploads/2020/09/O&#039;Neil.jpg">'],
    'in an entity-double-quoted shortcode' => ['[gallery link=&quot;https://example.test/dept/wp-content/uploads/2020/09/O&#039;Neil.jpg&quot;]'],
]);

it('decodes an entity in a link, and leaves an unterminated one as written', function (): void {
    // The query string is not part of a normalized link, so the entities are in the path
    $collector = collectedFrom(
        '<a href="https://example.test/dept/r&amp;d-team/">team</a>'
        .'<a href="https://example.test/dept/r&copy-staff/">staff</a>'
    );

    expect(\array_keys($collector->links()))->toBe(['//example.test/dept/r&d-team', '//example.test/dept/r&copy-staff']);
});

it('reads a URL in block attribute JSON, which carries no HTML entities, as before', function (): void {
    // core/image's JSON escapes an ampersand as \u0026, which is undone before the URL is read
    $collector = collectedFrom(
        '<!-- wp:acf/hero {"data":{"background":"https:\/\/example.test\/dept\/wp-content\/uploads\/2022\/02\/R\u0026D.jpg"}} /-->'
    );

    expect($collector->paths())->toBe(['2022/02/R&D.jpg']);
});

it('keeps image URLs for the resolution check, and not other uploads', function (): void {
    $collector = collectedFrom(
        '<img src="https://example.test/dept/wp-content/uploads/2020/01/e.JPG">'
        .'<a href="https://example.test/dept/wp-content/uploads/2020/01/f.pdf">pdf</a>'
    );

    expect($collector->paths())->toBe(['2020/01/e.JPG', '2020/01/f.pdf'])
        ->and(\array_keys($collector->imageUrls()))->toBe(['https://example.test/dept/wp-content/uploads/2020/01/e.JPG']);
});

it('takes upload URLs and links out of meta values', function (): void {
    $collector = collectedFrom('', [
        'hero_button_url' => ['https://example.test/dept/some-page/'],
        'side_html' => ['<img src="https://example.test/dept/wp-content/uploads/2020/02/g.png">'],
    ]);

    expect($collector->paths())->toBe(['2020/02/g.png'])
        ->and(\array_keys($collector->links()))->toBe(['//example.test/dept/some-page']);
});

it('reads a plugin\'s private meta for integers but not for URLs', function (): void {
    // An SEO plugin's canonical and social image are nothing the importer imports.
    $collector = collectedFrom('', [
        '_seopress_social_fb_img' => ['https://example.test/dept/wp-content/uploads/2020/03/og.jpg'],
        '_seopress_robots_canonical' => ['https://example.test/dept/some-page/'],
        '_private_image_id' => ['955'],
    ]);

    expect($collector->paths())->toBe([])
        ->and($collector->links())->toBe([])
        ->and($collector->ids())->toContain(955);
});

it('reads a redirect rule\'s target as a link, which the importer resolves', function (): void {
    $collector = collectedFrom('', ['_redirect_rule_to' => ['https://example.test/dept/new-home/']]);

    expect($collector->links())->toBe(['//example.test/dept/new-home' => 42]);
});

it('leaves a URL on another site alone', function (): void {
    $collector = collectedFrom(
        '<img src="https://elsewhere.test/wp-content/uploads/2020/01/h.jpg">'
        .'<a href="https://example.test/other-dept/page/">x</a>'
    );

    expect($collector->paths())->toBe([])
        ->and($collector->links())->toBe([]);
});

it('keeps a same-site permalink candidate, which could be an attachment page', function (): void {
    $collector = collectedFrom('<a href="https://example.test/dept/about/team-photo/#top">x</a>');

    expect($collector->links())->toBe(['//example.test/dept/about/team-photo' => 42]);
});

it('does not treat the home page, an archive or a system path as a permalink', function (string $href): void {
    expect(collectedFrom('<a href="'.$href.'">x</a>')->links())->toBe([]);
})->with([
    'https://example.test/dept/',
    'https://example.test/dept',
    'https://example.test/dept/category/news/',
    'https://example.test/dept/tag/events/',
    'https://example.test/dept/author/jane/',
    'https://example.test/dept/feed/',
    'https://example.test/dept/wp-admin/edit.php',
]);

it('reads the excerpt as well as the content', function (): void {
    $collector = referenceCollector();
    $collector->collect(['id' => 7, 'excerpt' => '<img class="wp-image-871" src="/x.jpg">']);

    expect($collector->namedIds())->toBe([871 => ['shape' => 'class:wp-image', 'item' => 7]]);
});

it('normalizes a link the way an item link is normalized', function (): void {
    expect(AttachmentReferenceCollector::normalizeLink('HTTPS://Example.test/Dept/Page/?x=1#y'))
        ->toBe('//example.test/dept/page');
});

// --- #102: the edges of each reader ---------------------------------------------------

it('records a named id from an item without an id as item 0', function (): void {
    $collector = referenceCollector();
    $collector->collect(['meta' => ['_thumbnail_id' => ['901']]]);

    expect($collector->namedIds())->toBe([901 => ['shape' => 'meta:_thumbnail_id', 'item' => 0]]);
});

it('reads a meta value served bare, as a number, or after a key that is not text', function (): void {
    $collector = referenceCollector();
    $collector->collect(['id' => 42, 'meta' => [5 => ['77'], '_thumbnail_id' => 901, 'logo_image' => '902']]);

    expect($collector->ids())->toBe([901, 902])
        ->and(\array_keys($collector->namedIds()))->toBe([901, 902]);
});

it('lists ids and paths in order whatever order they were found in', function (): void {
    $collector = collectedFrom(
        '<img class="wp-image-30"><img class="wp-image-10">'
        .'<img src="https://example.test/dept/wp-content/uploads/2021/b.png">'
        .'<img src="https://example.test/dept/wp-content/uploads/2020/a.png">'
    );

    expect($collector->ids())->toBe([10, 30])
        ->and($collector->paths())->toBe(['2020/a.png', '2021/b.png']);
});

it('keeps the first item a link or an image was seen on', function (): void {
    $collector = referenceCollector();
    $collector->collect(['id' => 1, 'content' => '<a href="https://example.test/dept/team/">x</a><img src="https://example.test/dept/wp-content/uploads/a.png">']);
    $collector->collect(['id' => 2, 'content' => '<a href="https://example.test/dept/team/">x</a><img src="https://example.test/dept/wp-content/uploads/a.png">']);

    expect($collector->links())->toBe(['//example.test/dept/team' => 1])
        ->and($collector->imageUrls())->toBe(['https://example.test/dept/wp-content/uploads/a.png' => 1]);
});

it('takes ids up to the largest a WordPress post id can be', function (string $id, array $ids): void {
    expect(collectedFrom('', ['_thumbnail_id' => [$id]])->ids())->toBe($ids);
})->with([
    'the largest' => ['2147483647', [2147483647]],
    'one past it' => ['2147483648', []],
    'one below it' => ['2147483646', [2147483646]],
]);

it('reads image and gallery block ids served as text', function (): void {
    $collector = collectedFrom('<!-- wp:image {"id":"501"} /--><!-- wp:gallery {"ids":["502","x"]} /-->');

    expect($collector->namedIds())->toBe([
        501 => ['shape' => 'block:image:id', 'item' => 42],
        502 => ['shape' => 'block:gallery:ids', 'item' => 42],
    ]);
});

it('takes the integers of block attributes it cannot decode', function (): void {
    expect(collectedFrom('<!-- wp:acf/broken {"image": 612, oops} /-->')->ids())->toBe([612]);
});

it('reads a URL whose slashes are escaped as code points', function (): void {
    // Each slash written as a backslash, `u` and its four hex digits, as json_encode() writes it
    // when told to escape slashes that way
    $escaped = \str_replace('/', \chr(92).'u002f', 'https://example.test/dept/wp-content/uploads/wide.png');
    $collector = collectedFrom('<!-- wp:acf/hero {"data":{"bg":"'.$escaped.'"}} /-->');

    expect($collector->paths())->toBe(['wide.png']);
});

it('keeps every image extension the image pipeline resolves', function (string $extension): void {
    $collector = collectedFrom('<img src="https://example.test/dept/wp-content/uploads/2020/a.'.$extension.'">');

    expect(\array_keys($collector->imageUrls()))->toBe(['https://example.test/dept/wp-content/uploads/2020/a.'.$extension]);
})->with(['avif', 'bmp', 'gif', 'heic', 'ico', 'jpeg', 'jpg', 'png', 'svg', 'tif', 'tiff', 'webp']);

it('does not treat any archive or system path as a permalink', function (string $segment): void {
    expect(collectedFrom('<a href="https://example.test/dept/'.$segment.'/something">x</a>')->links())->toBe([]);
})->with(['author', 'category', 'feed', 'page', 'tag', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login.php']);

it('names the KB attachments and advert gallery meta keys', function (string $key): void {
    expect(collectedFrom('', [$key => ['903']])->namedIds())->toBe([903 => ['shape' => 'meta:'.$key, 'item' => 42]]);
})->with(['_mkb_article_attachments', '_adverts_attachments_order']);

it('reads the base of the site the way it is written, in any case and with or without its slash', function (string $base, string $href, array $links): void {
    $collector = new AttachmentReferenceCollector($base);
    $collector->collect(['id' => 42, 'content' => '<a href="'.$href.'">x</a>']);

    expect($collector->links())->toBe($links);
})->with([
    'a root site' => ['https://example.test', 'https://example.test/team', ['//example.test/team' => 42]],
    'the subsite home, cased differently' => ['https://example.test/Dept', 'https://example.test/dept/', []],
    'the subsite home, against a base with its slash' => ['https://example.test/dept/', 'https://example.test/dept', []],
]);

it('reads no upload path from a URL with no path, or a path with no file', function (string $url): void {
    expect(AttachmentReferenceCollector::uploadsRelativePath($url))->toBeNull();
})->with([
    'no path' => ['https://example.test'],
    'a directory' => ['https://example.test/wp-content/uploads/2020/09'],
]);

it('trims a link before normalizing it', function (): void {
    expect(AttachmentReferenceCollector::normalizeLink('  https://Example.test/Team/  '))->toBe('//example.test/team');
});

it('reads an item id served as text', function (): void {
    $collector = referenceCollector();
    $collector->collect(['id' => '42', 'meta' => ['_thumbnail_id' => ['901']]]);

    expect($collector->namedIds())->toBe([901 => ['shape' => 'meta:_thumbnail_id', 'item' => 42]]);
});

it('reads the meta values after one that is neither text nor a list', function (): void {
    $collector = referenceCollector();
    $collector->collect(['id' => 42, 'meta' => ['_thumbnail_id' => [null, '901']]]);

    expect($collector->ids())->toBe([901]);
});

it('takes another block\'s numeric id as a candidate without naming it', function (): void {
    $collector = collectedFrom('<!-- wp:cover {"id":77} /-->');

    expect($collector->ids())->toBe([77])
        ->and($collector->namedIds())->toBe([]);
});

it('takes 1, the smallest attachment id', function (): void {
    expect(collectedFrom('', ['_thumbnail_id' => ['1']])->ids())->toBe([1]);
});

it('reads a subsite base and an archive segment in any case', function (string $base, string $href): void {
    $collector = new AttachmentReferenceCollector($base);
    $collector->collect(['id' => 42, 'content' => '<a href="'.$href.'">x</a>']);

    expect($collector->links())->toBe([]);
})->with([
    'the home of a base written in capitals' => ['https://example.test/Dept', 'https://example.test/Dept/'],
    'an archive segment written in capitals' => ['https://example.test/dept', 'https://example.test/dept/Category/news'],
]);

it('takes another block\'s list of ids as candidates without naming them', function (): void {
    $collector = collectedFrom('<!-- wp:acf/logos {"ids":[88]} /-->');

    expect($collector->ids())->toBe([88])
        ->and($collector->namedIds())->toBe([]);
});
