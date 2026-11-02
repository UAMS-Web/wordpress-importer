<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for how PeopleShortcodeProcessor maps `[show-people]` and `[show-team]` into a people set.
 *
 * A category resolves through the WordPress terms to a site-prefixed group, and each resolved
 * term is queued for creation and recorded against its page. `display` selects the detail
 * fields and the filter, and `url` selects the link behavior. `orderby` is
 * `PeopleShortcodeOrderByTest`, and attribute normalization is `ShortcodeBlockProcessorTest`.
 *
 * @command  php vendor/bin/pest tests/Integration/PeopleShortcodeProcessorTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    PeopleShortcodeProcessor::resetTermsUsed();
    BaseEntryCreator::clearDeferredTermQueue();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

afterEach(function (): void {
    PeopleShortcodeProcessor::resetTermsUsed();
    BaseEntryCreator::clearDeferredTermQueue();
});

/**
 * Build one WordPress term element.
 *
 * @param  string  $id  The term id
 * @param  string  $slug  The term slug
 * @param  string  $name  The term name
 * @param  string  $taxonomy  The WordPress taxonomy
 * @param  string  $parent  The parent term's slug
 * @return SimpleXMLElement The term
 */
function peopleTerm(
    string $id,
    string $slug,
    string $name,
    string $taxonomy = 'uamspeople-categories',
    string $parent = ''
): SimpleXMLElement {
    $xml = simplexml_load_string(
        '<root xmlns:wp="http://wordpress.org/export/1.2/"><wp:term>'
        ."<wp:term_id>$id</wp:term_id><wp:term_taxonomy>$taxonomy</wp:term_taxonomy><wp:term_slug>$slug</wp:term_slug>"
        ."<wp:term_parent>$parent</wp:term_parent><wp:term_name>$name</wp:term_name></wp:term></root>"
    );

    if (! $xml instanceof SimpleXMLElement) {
        throw new RuntimeException('Expected the term XML to parse.');
    }

    $term = $xml->xpath('wp:term')[0] ?? null;

    if (! $term instanceof SimpleXMLElement) {
        throw new RuntimeException('Expected a term element.');
    }

    return $term;
}

/**
 * Parse a shortcode and return the set's values.
 *
 * @param  array<int|string, mixed>  $attributes  The shortcode attributes
 * @param  array<int|string, mixed>  $terms  The WordPress terms
 * @param  string  $shortcode  The shortcode name
 * @param  string  $site  The site handle
 * @param  ?string  $pageSlug  The page slug
 * @return array<int|string, mixed> The set's values
 */
function peopleValues(
    array $attributes,
    array $terms = [],
    string $shortcode = 'show-people',
    string $site = 'default',
    ?string $pageSlug = null
): array {
    $set = new PeopleShortcodeProcessor($shortcode)->parse($attributes, $site, new AssetImporter, new BufferedOutput, $terms, $pageSlug);

    $attrs = $set['attrs'] ?? null;
    $values = is_array($attrs) ? ($attrs['values'] ?? null) : null;

    if (! is_array($values)) {
        throw new RuntimeException('Expected set values.');
    }

    return $values;
}

/**
 * Read the queued taxonomy terms.
 *
 * @return mixed The queue
 */
function peopleTermQueue(): mixed
{
    return new ReflectionProperty(BaseEntryCreator::class, 'deferredTermQueue')->getValue();
}

it('writes the set with its fixed fields and defaults', function (): void {
    $processor = new PeopleShortcodeProcessor;

    $set = $processor->parse([], 'default', new AssetImporter, new BufferedOutput);

    $attrs = $set['attrs'] ?? null;

    if (! is_array($attrs)) {
        throw new RuntimeException('Expected set attrs.');
    }

    expect($set['type'])->toBe('set');
    expect($attrs['id'])->toBeString()->not->toBe('');
    expect($attrs['values'])->toBe([
        'type' => 'people',
        'multiple_groups_selection' => false,
        'group_single' => null,
        'id_include' => 0,
        'id_exclude' => 0,
        'order_by' => 'sortpriority',
        'limit' => 0,
        'pagination' => false,
        'display_detail' => ['name', 'photo', 'position'],
        'entry_link_query' => false,
        'entry_link_url' => null,
        'entry_link_target' => false,
        'load_a_layout_preset' => 'uams',
        'layout' => 'grid',
        'columns' => 1,
        'groups_filter' => 'inactive',
        'theme' => 'white-card-theme',
        'composition' => 'img-left',
        'image_shape' => 'img-square',
        'image_effect' => false,
        'text_alignment' => 'text-left',
    ]);
});

it('reads an integer attribute from a number or numeric text', function (mixed $value, int $expected): void {
    expect(peopleValues(['ids' => $value, 'exclude' => $value, 'limit' => $value]))->toMatchArray([
        'id_include' => $expected,
        'id_exclude' => $expected,
        'limit' => $expected,
    ]);
})->with([
    'an integer' => [12, 12],
    'numeric text' => ['12', 12],
    'other text' => ['all', 0],
    'a list' => [[12], 0],
]);

it('reads the layout, and ignores one that is not text', function (mixed $layout, string $expected): void {
    expect(peopleValues(['layout' => $layout])['layout'])->toBe($expected);
})->with([
    'text' => ['table', 'table'],
    'a number' => [5, 'grid'],
]);

it('resolves a category through the terms to a site-prefixed group', function (): void {
    $terms = [peopleTerm('1', 'nurses', 'Nurses')];

    $values = peopleValues(['category' => 'nurses'], $terms, site: 'my_site');

    expect($values['multiple_groups_selection'])->toBeFalse();
    expect($values['group_single'])->toBe('my-site_nurses');
    expect($values)->not->toHaveKey('group_multiple');
});

it('resolves a child category to its full path', function (): void {
    $terms = [peopleTerm('1', 'clinical', 'Clinical'), peopleTerm('2', 'nurses', 'Nurses', parent: 'clinical')];

    expect(peopleValues(['category' => 'nurses'], $terms)['group_single'])->toBe('default_clinical_nurses');
});

it('resolves several categories to a group list, trimmed and skipping empties', function (): void {
    $terms = [peopleTerm('1', 'nurses', 'Nurses'), peopleTerm('2', 'doctors', 'Doctors')];

    $values = peopleValues(['category' => 'nurses, doctors,'], $terms);

    expect($values['multiple_groups_selection'])->toBeTrue();
    expect($values['group_multiple'])->toBe(['default_nurses', 'default_doctors']);
    expect($values)->not->toHaveKey('group_single');
});

it('reads the terms of the taxonomy the shortcode belongs to', function (string $shortcode, string $taxonomy): void {
    $terms = [peopleTerm('1', 'nurses', 'Nurses', $taxonomy)];

    expect(peopleValues(['category' => 'nurses'], $terms, $shortcode)['group_single'])->toBe('default_nurses');
})->with([
    'show-people' => ['show-people', 'uamspeople-categories'],
    'show-team' => ['show-team', 'tshowcase-categories'],
]);

it('ignores terms of another taxonomy', function (string $shortcode, string $taxonomy): void {
    $terms = [peopleTerm('1', 'nurses', 'Nurses', $taxonomy)];

    expect(peopleValues(['category' => 'nurses'], $terms, $shortcode)['group_single'])->toBe('nurses');
})->with([
    'show-people' => ['show-people', 'tshowcase-categories'],
    'show-team' => ['show-team', 'uamspeople-categories'],
]);

it('queues each resolved term for creation under people_groups', function (): void {
    $terms = [peopleTerm('1', 'nurses', 'Nurses'), peopleTerm('2', 'doctors', 'Doctors')];

    peopleValues(['category' => 'nurses,missing'], $terms, site: 'my_site');

    expect(peopleTermQueue())->toBe([
        'people_groups' => ['my_site' => ['nurses' => ['original' => 'nurses', 'name' => 'Nurses']]],
    ]);
});

it('warns and tells the operator about a category the terms do not hold', function (): void {
    /** @var ArrayObject<int, array{message: string, context: array<int|string, mixed>}> $warnings */
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use ($warnings): void {
        /** @var array<int|string, mixed> $context */
        $warnings->append(['message' => $message, 'context' => $context]);
    });

    $output = new BufferedOutput;
    $terms = [peopleTerm('1', 'nurses', 'Nurses')];

    new PeopleShortcodeProcessor()->parse(['category' => 'missing'], 'default', new AssetImporter, $output, $terms, 'a-page');
    new PeopleShortcodeProcessor()->parse(['category' => 'missing'], 'default', new AssetImporter, new BufferedOutput, $terms);

    $logged = $warnings->getArrayCopy();

    expect($logged)->toHaveCount(2);
    expect($logged[0]['message'])->toContain("PeopleShortcodeProcessor::parse: Term slug 'missing' not found in term map for people_group taxonomy");
    expect($logged[0]['context'])->toBe([
        'site' => 'default',
        'page_slug' => 'a-page',
        'term_slug' => 'missing',
        'available_term_slugs' => ['nurses'],
    ]);
    expect($logged[1]['context']['page_slug'] ?? null)->toBe('none');
    expect($output->fetch())->toContain("Warning: People group term 'missing' not found - skipping");
});

it('stays quiet about a category when the export holds no terms at all', function (): void {
    Log::shouldReceive('warning')->never();
    $output = new BufferedOutput;

    new PeopleShortcodeProcessor()->parse(['category' => 'missing'], 'default', new AssetImporter, $output);

    expect($output->fetch())->toBe('');
});

it('keeps the category as written when none of it resolves', function (string $category, string $key, mixed $expected): void {
    expect(peopleValues(['category' => $category])[$key])->toBe($expected);
})->with([
    'one category' => ['nurses', 'group_single', 'nurses'],
    'several categories' => ['nurses,doctors', 'group_multiple', ['nurses', 'doctors']],
]);

it('keeps the resolved group when only some of the categories resolve', function (string $category): void {
    $terms = [peopleTerm('1', 'nurses', 'Nurses')];

    expect(peopleValues(['category' => $category], $terms)['group_multiple'])->toBe(['default_nurses']);
})->with([
    'the unresolved one last' => ['nurses,missing'],
    'the unresolved one first' => ['missing,nurses'],
]);

it('records each page once against the term it uses', function (): void {
    $terms = [peopleTerm('1', 'nurses', 'Nurses'), peopleTerm('2', 'doctors', 'Doctors')];

    peopleValues(['category' => 'nurses,doctors'], $terms, pageSlug: 'a-page');
    peopleValues(['category' => 'nurses'], $terms, pageSlug: 'a-page');
    peopleValues(['category' => 'nurses'], $terms, pageSlug: 'b-page');

    expect(PeopleShortcodeProcessor::getTermsUsed())->toBe([
        'nurses' => ['a-page', 'b-page'],
        'doctors' => ['a-page'],
    ]);
});

it('tells apart page slugs that are numerically equal', function (): void {
    $terms = [peopleTerm('1', 'nurses', 'Nurses')];

    peopleValues(['category' => 'nurses'], $terms, pageSlug: '1');
    peopleValues(['category' => 'nurses'], $terms, pageSlug: '01');

    expect(PeopleShortcodeProcessor::getTermsUsed())->toBe(['nurses' => ['1', '01']]);
});

it('records no term without a page', function (?string $pageSlug): void {
    peopleValues(['category' => 'nurses'], [peopleTerm('1', 'nurses', 'Nurses')], pageSlug: $pageSlug);

    expect(PeopleShortcodeProcessor::getTermsUsed())->toBe([]);
})->with([
    'no slug' => [null],
    'an empty slug' => [''],
]);

it('names the site, the page and the value when it replaces an orderby', function (): void {
    /** @var ArrayObject<int, array{message: string, context: array<int|string, mixed>}> $warnings */
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use ($warnings): void {
        /** @var array<int|string, mixed> $context */
        $warnings->append(['message' => $message, 'context' => $context]);
    });

    peopleValues(['orderby' => 'lastname'], pageSlug: 'a-page');
    peopleValues(['orderby' => 'lastname']);

    $logged = $warnings->getArrayCopy();

    expect($logged)->toHaveCount(2);
    expect($logged[0]['message'])->toBe(
        "PeopleShortcodeProcessor::resolveOrderBy: orderby='lastname' has no People Showcase equivalent, importing as 'none'"
    );
    expect($logged[0]['context'])->toBe([
        'site' => 'default',
        'page_slug' => 'a-page',
        'orderby' => 'lastname',
        'order_by' => 'none',
    ]);
    expect($logged[1]['context']['page_slug'] ?? null)->toBe('none');
});

it('sorts the display attribute into detail fields and a filter', function (string $display, array $detail, string $filter): void {
    $values = peopleValues(['display' => $display]);

    expect($values['display_detail'])->toBe($detail);
    expect($values['groups_filter'])->toBe($filter);
})->with([
    'every detail field' => [
        'name,photo,freehtml,position,email,location,telephone,fax,website,deptweb,triweb,youtube',
        ['name', 'photo', 'freehtml', 'position', 'email', 'location', 'telephone', 'fax', 'website', 'deptweb', 'triweb', 'youtube'],
        'inactive',
    ],
    'unknown items are dropped' => ['name,,nothing', ['name'], 'inactive'],
    'the filter' => ['email,filter', ['email'], 'filter'],
    'the enhanced filter' => ['enhance-filter', ['name', 'photo', 'position'], 'enhance-filter'],
    'the isotope filter' => ['isotope-filter', ['name', 'photo', 'position'], 'isotope-filter'],
    'the dropdown filter' => ['dropdown-filter', ['name', 'photo', 'position'], 'dropdown-filter'],
]);

it('maps the url attribute to the link behavior', function (string $url, bool $query, ?string $target, bool $newWindow): void {
    expect(peopleValues(['url' => $url]))->toMatchArray([
        'entry_link_query' => $query,
        'entry_link_url' => $target,
        'entry_link_target' => $newWindow,
    ]);
})->with([
    'to the entry' => ['active', true, 'entry', false],
    'to the entry in a new window' => ['active_new', true, 'entry', true],
    'to the clinical profile' => ['active_custom', true, 'clinical_profile', false],
    'to the clinical profile in a new window' => ['active_custom_new', true, 'clinical_profile', true],
    'to the user url' => ['active_user', false, null, false],
    'to the full image' => ['full_image', false, null, false],
    'to a lightbox' => ['lightbox', false, null, false],
    'an unknown value' => ['elsewhere', false, null, false],
]);

it('builds a block from the parsed set', function (): void {
    $block = new PeopleShortcodeProcessor()->toPageBuilderBlock(
        ['category' => ' Nurses ', 'orderby' => 'title', 'layout' => 'table'],
        'default',
        new AssetImporter,
        new BufferedOutput
    );

    expect($block['id'])->toBeString()->not->toBe('');

    unset($block['id']);

    expect($block)->toBe([
        'type' => 'uamsweb_block_show-people',
        'enabled' => true,
        'category' => 'nurses',
        'order_by' => 'title',
        'layout' => 'table',
    ]);
});

it('builds a block with no category and the parsed defaults', function (): void {
    $block = new PeopleShortcodeProcessor()->toPageBuilderBlock([], 'default', new AssetImporter, new BufferedOutput);

    expect($block)->toMatchArray(['category' => null, 'order_by' => 'sortpriority', 'layout' => 'grid']);
});

it('reads a block from the top of a set that carries no usable values', function (array $set, string $orderBy): void {
    $processor = new class($set) extends PeopleShortcodeProcessor
    {
        /**
         * Keep the set this parse returns.
         *
         * @param  array<int|string, mixed>  $set  The set
         */
        public function __construct(private readonly array $set)
        {
            parent::__construct();
        }

        /**
         * Return the kept set.
         *
         * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
         * @param  string  $site  The site handle
         * @param  AssetImporter  $assetImporter  The asset importer
         * @param  OutputInterface  $output  Console output
         * @param  array<int|string, mixed>  $terms  The WordPress terms
         * @param  ?string  $pageSlug  The page slug
         * @return array<int|string, mixed> The kept set
         */
        public function parse(
            array $attributes,
            string $site,
            AssetImporter $assetImporter,
            OutputInterface $output,
            array $terms = [],
            ?string $pageSlug = null
        ): array {
            return $this->set;
        }
    };

    $block = $processor->toPageBuilderBlock([], 'default', new AssetImporter, new BufferedOutput);

    expect($block['order_by'])->toBe($orderBy);
    expect($block['layout'])->toBe('grid');
})->with([
    'no attrs' => [['order_by' => 'title'], 'title'],
    'attrs that are not a list' => [['order_by' => 'title', 'attrs' => 'x'], 'title'],
    'attrs with no values' => [['order_by' => 'title', 'attrs' => []], 'title'],
    'values that are not a list' => [['order_by' => 'title', 'attrs' => ['values' => 'x']], 'title'],
    'nothing to read' => [[], 'sorttext'],
]);
