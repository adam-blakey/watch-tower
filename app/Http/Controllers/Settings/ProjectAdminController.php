<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Concerns\FlashesNewApiKey;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteProjectData;
use App\Models\Environment;
use App\Models\ErrorGroup;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Watch\MaintenanceStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectAdminController extends Controller
{
    use FlashesNewApiKey;

    /**
     * How long, in hours, the replaced key may keep working after a rotation.
     * Zero revokes it at once.
     *
     * @var list<int>
     */
    public const ROTATION_GRACE_HOURS = [0, 1, 24, 168];

    /**
     * List the projects, with when retention last ran.
     */
    public function index(Request $request, MaintenanceStatus $maintenance): Response
    {
        $this->flashNewApiKeyToPage($request);

        $projects = Project::query()
            ->orderBy('name')
            ->withCount('admins')
            ->with(['environments' => fn ($query) => $query->orderBy('name')])
            ->get(['id', 'name', 'slug', 'description', 'sampling_rate', 'retention_days', 'created_at']);

        /*
         * Issues live in the telemetry database, so their counts cannot be a subquery on the projects query.
         */
        $openIssueCounts = ErrorGroup::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->where('status', 'unresolved')
            ->groupBy('project_id')
            ->selectRaw('project_id, COUNT(*) AS total')
            ->pluck('total', 'project_id');

        $projects = $projects
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'description' => $project->description,
                'environments' => $project->environments->map(fn (Environment $environment) => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'api_key_prefix' => $environment->api_key_prefix,
                    'api_key_last_used_at' => $environment->api_key_last_used_at?->toISOString(),
                    'previous_api_key_expires_at' => $environment->hasActivePreviousApiKey()
                        ? $environment->previous_api_key_expires_at->toISOString()
                        : null,
                ])->all(),
                'sampling_rate' => $project->sampling_rate,
                'retention_days' => $project->retention_days,
                'admins_count' => $project->admins_count,
                'open_issues_count' => (int) ($openIssueCounts[$project->id] ?? 0),
                'created_at' => $project->created_at?->toISOString(),
            ]);

        $lastPrune = $maintenance->lastPrune();

        return Inertia::render('settings/projects/index', [
            'projects' => $projects,
            'lastPrune' => $lastPrune ? [
                'finished_at' => $lastPrune->finished_at->toISOString(),
                'rows_deleted' => $lastPrune->rows_deleted,
            ] : null,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('settings/projects/create', [
            'admins' => $this->adminOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'unique:projects,slug', 'regex:/^[a-z0-9-]+$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sampling_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'admin_ids' => ['array'],
            'admin_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $project = new Project([
            'organization_id' => Organization::default()->id,
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'sampling_rate' => $validated['sampling_rate'],
            'retention_days' => $validated['retention_days'],
        ]);
        $apiKey = $project->getConnection()->transaction(function () use ($project): string {
            $project->save();

            return $project->createEnvironment();
        });

        $project->admins()->sync($validated['admin_ids'] ?? []);

        return redirect()->route('settings.projects.index')
            ->with('success', 'Project created.')
            ->with('new_api_key', $this->newApiKeyFlash($project->name, $apiKey));
    }

    public function edit(Request $request, Project $project): Response
    {
        $this->flashNewApiKeyToPage($request);

        return Inertia::render('settings/projects/edit', [
            'environments' => $project->environments()->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (Environment $environment) => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'slug' => $environment->slug,
                    'is_default' => $environment->is_default,
                    'api_key_prefix' => $environment->api_key_prefix,
                    'api_key_last_used_at' => $environment->api_key_last_used_at?->toISOString(),
                    'previous_api_key_expires_at' => $environment->hasActivePreviousApiKey()
                        ? $environment->previous_api_key_expires_at->toISOString()
                        : null,
                ])
                ->all(),
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'description' => $project->description,
                'sampling_rate' => $project->sampling_rate,
                'retention_days' => $project->retention_days,
                'admin_ids' => $project->admins()->pluck('users.id')->all(),
            ],
            'admins' => $this->adminOptions(),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/', Rule::unique('projects', 'slug')->ignore($project->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'sampling_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'admin_ids' => ['array'],
            'admin_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $project->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'sampling_rate' => $validated['sampling_rate'],
            'retention_days' => $validated['retention_days'],
        ]);

        $project->admins()->sync($validated['admin_ids'] ?? []);

        return redirect()->route('settings.projects.index')->with('success', 'Project updated.');
    }

    /**
     * Delete a project.
     *
     * The project is hidden from everyone straight away (admins detached,
     * `deleting_at` set, which drops it from the default `Project` scope and so
     * from lists, URLs and ingest). Its telemetry is then deleted in chunks
     * by a queued job, which finally removes the row.
     */
    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $confirmation = $request->input('confirmation');

        if ($confirmation !== $project->slug) {
            throw ValidationException::withMessages([
                'confirmation' => 'Type the project slug exactly to confirm deletion.',
            ]);
        }

        $project->getConnection()->transaction(function () use ($project) {
            $project->admins()->detach();
            $project->forceFill(['deleting_at' => now()])->save();
        });

        DeleteProjectData::dispatch($project->id);

        return redirect()->route('settings.projects.index')->with('success', "Project '{$project->name}' and all its data have been deleted.");
    }

    /**
     * @return array<int, array{id: int, name: string, email: string}>
     */
    private function adminOptions(): array
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])
            ->all();
    }
}
