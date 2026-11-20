<?php

use App\Models\Environment;
use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\LogEntry;
use App\Models\Project;
use App\Models\Trace;
use App\Models\User;
use App\Watch\Stats\EnvironmentFilter;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]));

    $this->project = Project::factory()->create();
    $this->production = $this->project->environments()->sole();
    $this->staging = Environment::factory()->for($this->project)->create(['name' => 'Staging', 'slug' => 'staging']);
});

/**
 * @param  list<string>  $slugs
 */
function environmentFilterFor(Project $project, array $slugs): EnvironmentFilter
{
    return EnvironmentFilter::fromRequest(Request::create('/', 'GET', ['env' => $slugs]), $project);
}

it('selects environments by slug, drops unknown slugs and selects nothing without any', function () {
    $project = $this->project;

    expect(environmentFilterFor($project, ['staging', 'nope'])->slugs)->toBe(['staging'])
        ->and(environmentFilterFor($project, ['nope'])->ids)->toBe([])
        ->and(EnvironmentFilter::fromRequest(Request::create('/'), $project)->ids)->toBe([])
        ->and(EnvironmentFilter::fromRequest(Request::create('/', 'GET', ['env' => 'staging']), $project)->slugs)->toBe(['staging']);
});

it('filters the requests page by environment', function () {
    Trace::factory()->create(['project_id' => $this->project->id, 'environment_id' => $this->production->id]);
    Trace::factory()->create(['project_id' => $this->project->id, 'environment_id' => $this->staging->id]);
    Trace::factory()->create(['project_id' => $this->project->id, 'environment_id' => $this->staging->id]);

    $total = fn (array $query = []) => $this->get(route('projects.requests.index', [$this->project, ...$query]))
        ->assertOk()
        ->viewData('page')['props']['summary']['totals']['total'];

    expect($total())->toBe(3)
        ->and($total(['env' => ['production']]))->toBe(1)
        ->and($total(['env' => ['staging']]))->toBe(2)
        ->and($total(['env' => ['staging', 'unknown']]))->toBe(2)
        ->and($total(['env' => ['unknown']]))->toBe(3);
});

it('filters the logs page by environment', function () {
    $log = fn (?string $environmentId) => LogEntry::query()->create([
        'project_id' => $this->project->id,
        'environment_id' => $environmentId,
        'level' => 'info',
        'message' => 'hello',
        'occurred_at' => now(),
    ]);
    $log($this->production->id);
    $log($this->staging->id);

    $this->get(route('projects.logs.index', [$this->project, 'env' => ['staging']]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('logs.total', 1));
});

it('lists only the exceptions and issues seen in the selected environments', function () {
    $stagingOnly = ErrorGroup::factory()->create(['project_id' => $this->project->id, 'display_number' => 1]);
    $both = ErrorGroup::factory()->create(['project_id' => $this->project->id, 'display_number' => 2]);

    $occur = fn (ErrorGroup $group, ?string $environmentId) => ErrorOccurrence::factory()->create([
        'project_id' => $this->project->id,
        'error_group_id' => $group->id,
        'environment_id' => $environmentId,
    ]);
    $occur($stagingOnly, $this->staging->id);
    $occur($both, $this->staging->id);
    $occur($both, $this->production->id);
    $occur($both, $this->production->id);

    $this->get(route('projects.exceptions.index', [$this->project, 'env' => ['production'], 'range' => '24h']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.totals.total', 2)
            ->has('groups', 1)
            ->where('groups.0.id', $both->id)
            ->where('groups.0.total_count', 2));

    $this->get(route('projects.issues.index', [$this->project, 'env' => ['production']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('groups.data', 1)
            ->where('groups.data.0.id', $both->id)
            ->where('groups.data.0.total_count', 2)
            ->where('counts.open', 1));

    $this->get(route('projects.issues.index', [$this->project, 'env' => ['staging']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('groups.data', 2)
            ->where('counts.open', 2));

    $this->get(route('projects.issues.index', [$this->project]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('groups.data', 2));
});

it('names the environments of an issue through their relation', function () {
    $group = ErrorGroup::factory()->create(['project_id' => $this->project->id, 'display_number' => 1]);
    foreach ([$this->production->id, $this->production->id, $this->staging->id] as $environmentId) {
        ErrorOccurrence::factory()->create([
            'project_id' => $this->project->id,
            'error_group_id' => $group->id,
            'environment_id' => $environmentId,
            'environment' => 'ignored free text',
        ]);
    }

    $this->get(route('projects.issues.show', [$this->project, 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('issue.environments', [
                ['environment' => 'Production', 'count' => 2],
                ['environment' => 'Staging', 'count' => 1],
            ]));

    $this->get(route('projects.exceptions.show', [$this->project, $group->id, 'env' => ['staging']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('exception.environments', [
                ['environment' => 'Production', 'count' => 2],
                ['environment' => 'Staging', 'count' => 1],
            ])
            ->where('exception.occurrences.day', 1));
});

it('shares the environments of the project and the selection', function () {
    $this->get(route('projects.dashboard', [$this->project, 'env' => ['staging', 'unknown']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currentProject.environments', [
                ['id' => $this->production->id, 'slug' => 'production', 'name' => 'Production'],
                ['id' => $this->staging->id, 'slug' => 'staging', 'name' => 'Staging'],
            ])
            ->where('currentProject.selected_environments', ['staging']));
});
