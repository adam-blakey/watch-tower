<?php

use App\Jobs\DeleteEnvironmentData;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Watch\TelemetryPruner;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    Organization::factory()->create();
    $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    $this->project = Project::factory()->withApiKey('pk_test_production')->create();
    $this->staging = Environment::factory()->withApiKey('pk_test_staging')->create([
        'project_id' => $this->project->id,
        'name' => 'Staging',
        'slug' => 'staging',
    ]);
});

function environmentRoute(string $action, Project $project, ?Environment $environment = null): string
{
    return route("settings.projects.environments.{$action}", array_filter([$project->slug, $environment?->id]));
}

it('lists the environments on the edit page, default first, without full keys', function () {
    $response = $this->actingAs($this->admin)->get(route('settings.projects.edit', $this->project->slug));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('environments', 2)
        ->where('environments.0.is_default', true)
        ->where('environments.1.slug', 'staging')
        ->where('environments.1.api_key_prefix', 'pk_test_st'));

    expect($response->getContent())->not->toContain('pk_test_staging');
});

it('creates an environment and shows its key once', function () {
    $this->actingAs($this->admin)
        ->post(environmentRoute('store', $this->project), ['name' => 'QA', 'slug' => 'qa'])
        ->assertRedirect(route('settings.projects.edit', $this->project->slug));

    $created = $this->project->environments()->where('slug', 'qa')->sole();
    $response = $this->get(route('settings.projects.edit', $this->project->slug));
    $shown = $response->inertiaPage()['flash']['new_api_key'];

    expect($created->is_default)->toBeFalse()
        ->and($shown['project'])->toBe("{$this->project->name} (QA)")
        ->and(Environment::findByApiKey($shown['key'])?->id)->toBe($created->id)
        ->and(json_encode($response->inertiaPage()['props']))->not->toContain($shown['key']);

    $this->get(route('settings.projects.edit', $this->project->slug))
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('new_api_key'));
});

it('validates the name and a slug that is well formed and unique within the project', function (array $input, string $field) {
    $this->actingAs($this->admin)
        ->post(environmentRoute('store', $this->project), $input)
        ->assertSessionHasErrors($field);
})->with([
    'no name' => [['slug' => 'qa'], 'name'],
    'taken slug' => [['name' => 'Other', 'slug' => 'staging'], 'slug'],
    'upper case slug' => [['name' => 'QA', 'slug' => 'QA'], 'slug'],
    'spaces in slug' => [['name' => 'QA', 'slug' => 'q a'], 'slug'],
    'long slug' => [['name' => 'QA', 'slug' => str_repeat('a', 65)], 'slug'],
]);

it('allows the same slug in another project', function () {
    $other = Project::factory()->create();

    $this->actingAs($this->admin)
        ->post(environmentRoute('store', $other), ['name' => 'Staging', 'slug' => 'staging'])
        ->assertSessionHasNoErrors();
});

it('renames an environment but keeps its slug', function () {
    $this->actingAs($this->admin)
        ->patch(environmentRoute('update', $this->project, $this->staging), ['name' => 'Pre-prod', 'slug' => 'changed'])
        ->assertRedirect(route('settings.projects.edit', $this->project->slug));

    expect($this->staging->fresh()->only(['name', 'slug']))->toBe(['name' => 'Pre-prod', 'slug' => 'staging']);

    $this->patch(environmentRoute('update', $this->project, $this->staging), ['name' => ''])->assertSessionHasErrors('name');
});

it('rotates one environment key without touching the others', function () {
    $this->actingAs($this->admin)
        ->post(environmentRoute('rotate-key', $this->project, $this->staging), ['grace_hours' => 0])
        ->assertSessionHas('new_api_key');

    expect(Environment::findByApiKey('pk_test_staging'))->toBeNull()
        ->and(Environment::findByApiKey('pk_test_production')?->id)->toBe($this->project->defaultEnvironment->id)
        ->and($this->project->defaultEnvironment->fresh()->api_key_version)->toBe(1)
        ->and($this->staging->fresh()->api_key_version)->toBe(2);
});

it('does not reach an environment through another project', function () {
    $other = Project::factory()->create();

    $this->actingAs($this->admin)
        ->post(environmentRoute('rotate-key', $other, $this->staging), ['grace_hours' => 0])
        ->assertNotFound();
    $this->delete(environmentRoute('destroy', $other, $this->staging))->assertNotFound();
});

it('lets only super admins manage environments', function () {
    $projectAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->project->admins()->attach($projectAdmin->id);
    Bus::fake();

    $this->actingAs($projectAdmin);
    $this->post(environmentRoute('store', $this->project), ['name' => 'QA', 'slug' => 'qa'])->assertForbidden();
    $this->patch(environmentRoute('update', $this->project, $this->staging), ['name' => 'X'])->assertForbidden();
    $this->post(environmentRoute('rotate-key', $this->project, $this->staging), ['grace_hours' => 0])->assertForbidden();
    $this->delete(environmentRoute('destroy', $this->project, $this->staging))->assertForbidden();

    auth()->logout();
    $this->post(environmentRoute('store', $this->project), ['name' => 'QA', 'slug' => 'qa'])->assertRedirect(route('login'));

    expect($this->project->environments()->count())->toBe(2)
        ->and($this->staging->fresh()->name)->toBe('Staging');
    Bus::assertNotDispatched(DeleteEnvironmentData::class);
});

it('refuses to delete the default environment', function () {
    Bus::fake();

    $this->actingAs($this->admin)
        ->delete(environmentRoute('destroy', $this->project, $this->project->defaultEnvironment))
        ->assertSessionHasErrors('environment');

    Bus::assertNotDispatched(DeleteEnvironmentData::class);
    expect($this->project->environments()->count())->toBe(2);
});

it('hides a deleted environment at once and queues its data deletion', function () {
    Bus::fake();

    $this->actingAs($this->admin)
        ->delete(environmentRoute('destroy', $this->project, $this->staging))
        ->assertRedirect(route('settings.projects.edit', $this->project->slug));

    Bus::assertDispatched(DeleteEnvironmentData::class, fn (DeleteEnvironmentData $job) => $job->environmentId === $this->staging->id);

    expect($this->project->environments()->pluck('slug')->all())->toBe(['production'])
        ->and(Environment::findByApiKey('pk_test_staging'))->toBeNull();

    $this->withHeader('Authorization', 'Bearer pk_test_staging')->postJson('/api/agent-auth')->assertUnauthorized();
});

it('deletes only the rows of the deleted environment, then the environment', function () {
    $other = Environment::factory()->create(['project_id' => $this->project->id]);
    $tables = array_keys(TelemetryPruner::TIME_COLUMNS);

    foreach ([$this->staging->id, $other->id, null] as $environmentId) {
        seedTelemetry($this->project, now());

        foreach ($tables as $table) {
            dbTable($table)->whereNull('environment_id')->update(['environment_id' => $environmentId]);
        }
    }

    $this->staging->forceFill(['deleting_at' => now()])->save();

    DeleteEnvironmentData::dispatchSync($this->staging->id);

    foreach ($tables as $table) {
        expect(dbTable($table)->where('environment_id', $this->staging->id)->count())->toBe(0, "{$table} staging")
            ->and(dbTable($table)->where('environment_id', $other->id)->count())->toBeGreaterThan(0, "{$table} other")
            ->and(dbTable($table)->whereNull('environment_id')->count())->toBeGreaterThan(0, "{$table} null");
    }

    expect(Environment::withoutGlobalScope('notDeleting')->find($this->staging->id))->toBeNull()
        ->and(dbTable('error_groups')->count())->toBe(3);
});

it('never deletes a live or default environment', function () {
    seedTelemetry($this->project, now());
    dbTable('traces')->update(['environment_id' => $this->staging->id]);

    DeleteEnvironmentData::dispatchSync($this->staging->id);

    $default = $this->project->defaultEnvironment;
    $default->forceFill(['deleting_at' => now()])->save();
    DeleteEnvironmentData::dispatchSync($default->id);

    expect(Environment::withoutGlobalScope('notDeleting')->count())->toBe(2)
        ->and(dbTable('traces')->where('environment_id', $this->staging->id)->count())->toBe(1);
});
