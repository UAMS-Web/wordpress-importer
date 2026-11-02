<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins `.github/workflows/assign-author.yml` (wordpress-importer-archive#1256), which assigns each pull
 * request, and every issue it closes, to exactly the pull request's author.
 *
 * The shape tests pin what the job runs on: `opened` and a merged `closed`,
 * never a bot's pull request, the condition the shared template carries, and
 * only the two scopes it writes with. The behavior tests run the step's own script, read
 * from the workflow, against a stub `gh` that records every call. The real `jq`
 * filters run, so a changed filter is exercised rather than trusted. Each
 * failure the script must report shows here as a non-zero exit: a dropped
 * assignee, a failed write, a GraphQL error and a truncated page.
 *
 * The same workflow runs in `uams-statamic` and `uamswp-migration-api`, and
 * `uams-statamic`'s copy of this test is where it was first written. This copy
 * boots nothing, so it runs in the standalone `Unit` suite.
 *
 * @command  php vendor/bin/pest tests/Unit/AssignAuthorWorkflowTest.php
 */

/**
 * Pull request number every behavior case uses.
 */
const ASSIGN_AUTHOR_PR = '4242';

/**
 * Repository every behavior case uses.
 */
const ASSIGN_AUTHOR_REPO = 'UAMS-Web/aether-wordpress-importer';

/**
 * The parsed workflow.
 *
 * @param  string  $path  Workflow path, relative to the repository root.
 * @return array<mixed> The workflow's top-level keys.
 */
function assignAuthorWorkflow(string $path = '.github/workflows/assign-author.yml'): array
{
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/'.$path);

    // Fail with the reason rather than on a later offset read
    if (! is_array($workflow)) {
        throw new RuntimeException('assign-author.yml did not parse to a mapping');
    }

    return $workflow;
}

/**
 * The workflow's single job.
 *
 * @param  string  $path  Workflow path, relative to the repository root.
 * @return array<mixed> The job's keys.
 */
function assignAuthorJob(string $path = '.github/workflows/assign-author.yml'): array
{
    $jobs = assignAuthorWorkflow($path)['jobs'] ?? null;

    // Require exactly one job, so a second, unguarded one cannot slip in
    if (! is_array($jobs) || count($jobs) !== 1) {
        throw new RuntimeException('assign-author.yml must declare exactly one job');
    }

    $job = reset($jobs);

    // Narrow the job to a mapping for the assertions that read it
    if (! is_array($job)) {
        throw new RuntimeException('assign-author.yml job is not a mapping');
    }

    return $job;
}

/**
 * The step's shell script.
 */
function assignAuthorScript(): string
{
    $steps = assignAuthorJob()['steps'] ?? null;

    // Require exactly one step carrying a script
    if (! is_array($steps) || count($steps) !== 1 || ! is_array($steps[0]) || ! is_string($steps[0]['run'] ?? null)) {
        throw new RuntimeException('assign-author.yml must declare one step with a run script');
    }

    return $steps[0]['run'];
}

/**
 * Run the step's script against a stub `gh`.
 *
 * The stub logs each call's arguments, one call per line, to `calls.log`. A
 * `PATCH` answers with the assignee it was sent, unless `drop` exists (the
 * answer GitHub gives for a login it cannot assign) or `fail-patch` exists
 * (a non-zero exit). A GraphQL call prints `closing.json`, or exits non-zero when
 * `fail-graphql` exists.
 *
 * @param  array<string, string>  $env  Event values overriding the opened-by-a-user defaults
 * @param  array<string, string>  $files  Stub control files to create, by name
 * @return array{exit: int|null, output: string, calls: list<string>} The run's exit code, output and logged calls
 */
function runAssignAuthor(array $env = [], array $files = []): array
{
    $directory = sys_get_temp_dir().'/assign-author-test-'.bin2hex(random_bytes(6));
    mkdir($directory.'/bin', 0777, true);

    // Write the stub, which answers from the control files beside it
    file_put_contents($directory.'/bin/gh', <<<'SH'
        #!/bin/bash
        dir="$(cd "$(dirname "$0")/.." && pwd)"
        printf '%s\n' "$*" >> "$dir/calls.log"
        if [ "$1 $2" = "api graphql" ]; then
          if [ -e "$dir/fail-graphql" ]; then
            exit 1
          fi
          cat "$dir/closing.json"
          exit 0
        fi
        if [ -e "$dir/fail-patch" ]; then
          exit 1
        fi
        for arg in "$@"; do
          case "$arg" in
            'assignees[]='*) login="${arg#assignees[]=}" ;;
          esac
        done
        if [ -e "$dir/drop" ]; then
          echo '{"assignees":[{"login":"someone-else"}]}'
        else
          printf '{"assignees":[{"login":"%s"}]}\n' "$login"
        fi
        SH);
    chmod($directory.'/bin/gh', 0755);

    // Shadow jq with the real one minus carriage returns: jq.exe on Windows ends each
    // output line with \r\n, which a runner's jq does not, and `read` keeps the \r. The
    // shim drops its own directory, the first PATH entry, to reach the real jq, and
    // pipefail keeps jq's exit status for the script's `jq -e` checks
    file_put_contents($directory.'/bin/jq', <<<'SH'
        #!/bin/bash
        set -o pipefail
        PATH="${PATH#*:}" jq "$@" | tr -d '\r'
        SH);
    chmod($directory.'/bin/jq', 0755);

    // Default to a pull request that closes nothing
    $files += ['closing.json' => '{"data":{"repository":{"pullRequest":{"closingIssuesReferences":{"totalCount":0,"nodes":[]}}}}}'];

    // Create the control files this case asks for
    foreach ($files as $name => $contents) {
        file_put_contents($directory.'/'.$name, $contents);
    }

    // Run the script as the runner does, with the stub first on PATH
    $process = new Process(['bash', '-e', '-c', assignAuthorScript()], null, [
        'PATH' => $directory.'/bin:'.getenv('PATH'),
        'RUNNER_TEMP' => $directory,
        'GH_TOKEN' => 'stub',
        'REPO' => ASSIGN_AUTHOR_REPO,
        'NUMBER' => ASSIGN_AUTHOR_PR,
        'AUTHOR' => 'the-author',
        'AUTHOR_TYPE' => 'User',
        'ACTION' => 'opened',
        'MERGED' => 'false',
        ...$env,
    ]);
    $process->run();

    // Read the calls before removing the scratch directory
    $log = is_file($directory.'/calls.log') ? (string) file_get_contents($directory.'/calls.log') : '';
    $calls = array_values(array_filter(explode("\n", $log), fn (string $line): bool => $line !== ''));

    (new Process(['rm', '-rf', $directory]))->run();

    return [
        'exit' => $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
        'calls' => $calls,
    ];
}

/**
 * The stub-log line an assignment of one number writes.
 */
function assignAuthorPatch(string $number): string
{
    return 'api -X PATCH repos/'.ASSIGN_AUTHOR_REPO.'/issues/'.$number.' -f assignees[]=the-author';
}

/**
 * A GraphQL answer naming the given issues.
 *
 * @param  list<array{0: string, 1: int}>  $issues  Repository and number of each closed issue
 * @param  int|null  $totalCount  Total to report, defaulting to the number of issues
 */
function assignAuthorClosing(array $issues, ?int $totalCount = null): string
{
    $nodes = array_map(
        fn (array $issue): array => ['number' => $issue[1], 'repository' => ['nameWithOwner' => $issue[0]]],
        $issues,
    );

    return (string) json_encode(['data' => ['repository' => ['pullRequest' => ['closingIssuesReferences' => [
        'totalCount' => $totalCount ?? count($nodes),
        'nodes' => $nodes,
    ]]]]]);
}

it('triggers on a pull request opening or closing and on nothing else', function (): void {
    expect(assignAuthorWorkflow()['on'] ?? null)->toBe(['pull_request' => ['types' => ['opened', 'closed']]]);
});

it('runs its job only for a user on an opened or merged pull request', function (): void {
    // A remote-CI gate may precede this, so pin how the condition ends rather than all of it
    expect(assignAuthorJob()['if'] ?? null)->toEndWith(
        "github.event.pull_request.user.type != 'Bot' && (github.event.action == 'opened' || (github.event.action == 'closed' && github.event.pull_request.merged == true))"
    );
});

it('carries the job condition of the shared template', function (): void {
    // The sync moves the two together, so this holds on either side of a template change
    $template = assignAuthorJob('.claude/skills/writing-pull-requests/templates/assign-author.yml');

    expect($template['if'] ?? null)->toBeString()
        ->and(assignAuthorJob()['if'] ?? null)->toBe($template['if'] ?? null);
});

it('grants no permission beyond writing issues and pull requests', function (): void {
    expect(assignAuthorWorkflow()['permissions'] ?? null)->toBe([])
        ->and(assignAuthorJob()['permissions'] ?? null)->toBe(['issues' => 'write', 'pull-requests' => 'write']);
});

it('assigns an opened pull request to exactly its author', function (): void {
    $run = runAssignAuthor();

    expect($run['exit'])->toBe(0)
        ->and($run['calls'])->toBe([assignAuthorPatch(ASSIGN_AUTHOR_PR)]);
});

it('assigns a merged pull request and the issues it closes in this repository', function (): void {
    $run = runAssignAuthor(
        ['ACTION' => 'closed', 'MERGED' => 'true'],
        ['closing.json' => assignAuthorClosing([
            [ASSIGN_AUTHOR_REPO, 11],
            ['UAMS-Web/uams-statamic', 5],
            [ASSIGN_AUTHOR_REPO, 12],
        ])],
    );

    expect($run['exit'])->toBe(0)
        ->and(array_slice($run['calls'], 1))->toBe([
            assignAuthorPatch('11'),
            assignAuthorPatch('12'),
            assignAuthorPatch(ASSIGN_AUTHOR_PR),
        ])
        ->and($run['calls'][0])->toStartWith('api graphql')
        ->and($run['output'])->toContain('Skipping UAMS-Web/uams-statamic#5');
});

it('assigns only a merged pull request that closes nothing', function (): void {
    $run = runAssignAuthor(['ACTION' => 'closed', 'MERGED' => 'true']);

    expect($run['exit'])->toBe(0)
        ->and(array_slice($run['calls'], 1))->toBe([assignAuthorPatch(ASSIGN_AUTHOR_PR)]);
});

it('changes nothing for a pull request closed without merging', function (): void {
    $run = runAssignAuthor(
        ['ACTION' => 'closed', 'MERGED' => 'false'],
        ['closing.json' => assignAuthorClosing([[ASSIGN_AUTHOR_REPO, 11]])],
    );

    expect($run['exit'])->toBe(0)
        ->and($run['calls'])->toBe([]);
});

it('changes nothing for a bot author', function (): void {
    $run = runAssignAuthor(['AUTHOR_TYPE' => 'Bot']);

    expect($run['exit'])->toBe(0)
        ->and($run['calls'])->toBe([]);
});

it('fails when the answer shows a different assignee', function (): void {
    // GitHub answers 200 without the login it could not assign
    $run = runAssignAuthor([], ['drop' => '']);

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain("::error::#4242 is assigned to 'someone-else'");
});

it('fails when an assignment call fails', function (): void {
    $run = runAssignAuthor([], ['fail-patch' => '']);

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain('::error::Assigning #4242 to the-author failed.');
});

it('fails without assigning anything when the closing references cannot be read', function (): void {
    $run = runAssignAuthor(
        ['ACTION' => 'closed', 'MERGED' => 'true'],
        ['closing.json' => '{"errors":[{"message":"rate limited"}],"data":null}'],
    );

    expect($run['exit'])->not->toBe(0)
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['output'])->toContain('returned no closing references');
});

it('fails without assigning anything when the closing references are truncated', function (): void {
    $run = runAssignAuthor(
        ['ACTION' => 'closed', 'MERGED' => 'true'],
        ['closing.json' => assignAuthorClosing([[ASSIGN_AUTHOR_REPO, 11]], 101)],
    );

    expect($run['exit'])->not->toBe(0)
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['output'])->toContain('closes more issues than could be read');
});

it('fails without assigning anything when the closing references call fails', function (): void {
    $run = runAssignAuthor(['ACTION' => 'closed', 'MERGED' => 'true'], ['fail-graphql' => '']);

    expect($run['exit'])->not->toBe(0)
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['output'])->toContain('::error::Reading the issues #4242 closes failed.');
});

it('asks for the first page of closing references by owner, name and integer number', function (): void {
    $run = runAssignAuthor(['ACTION' => 'closed', 'MERGED' => 'true']);

    // The stub answers any query, so the query text itself is what this pins
    expect($run['calls'][0] ?? '')
        ->toContain('query($owner: String!, $name: String!, $number: Int!)')
        ->toContain('pullRequest(number: $number) { closingIssuesReferences(first: 100) { totalCount nodes { number repository { nameWithOwner } } } }')
        ->toContain('-F owner=UAMS-Web -F name=aether-wordpress-importer -F number=4242');
});
