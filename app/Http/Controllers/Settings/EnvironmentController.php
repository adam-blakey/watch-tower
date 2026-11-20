<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Concerns\FlashesNewApiKey;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteEnvironmentData;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EnvironmentController extends Controller
{
    use FlashesNewApiKey;

    public function store(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/', Rule::unique('environments', 'slug')->where('project_id', $project->id)],
        ]);

        $apiKey = $project->createEnvironment($validated['name'], $validated['slug']);

        return $this->backToProject($project)
            ->with('success', "Environment '{$validated['name']}' created.")
            ->with('new_api_key', $this->newApiKeyFlash("{$project->name} ({$validated['name']})", $apiKey));
    }

    public function update(Request $request, Project $project, Environment $environment): RedirectResponse
    {
        $environment->update($request->validate(['name' => ['required', 'string', 'max:255']]));

        return $this->backToProject($project)->with('success', 'Environment updated.');
    }

    public function rotateKey(Request $request, Project $project, Environment $environment): RedirectResponse
    {
        $validated = $request->validate([
            'grace_hours' => ['required', 'integer', Rule::in(ProjectAdminController::ROTATION_GRACE_HOURS)],
        ]);

        $apiKey = $environment->rotateApiKey($validated['grace_hours'] * 3600);

        return $this->backToProject($project)
            ->with('success', "API key rotated for '{$project->name}' ({$environment->name}).")
            ->with('new_api_key', $this->newApiKeyFlash("{$project->name} ({$environment->name})", $apiKey));
    }

    public function destroy(Project $project, Environment $environment): RedirectResponse
    {
        if ($project->environments()->count() === 1) {
            throw ValidationException::withMessages([
                'environment' => 'A project needs at least one environment.',
            ]);
        }

        $environment->forceFill(['deleting_at' => now()])->save();

        DeleteEnvironmentData::dispatch($environment->id);

        return $this->backToProject($project)->with('success', "Environment '{$environment->name}' and its data have been deleted.");
    }

    private function backToProject(Project $project): RedirectResponse
    {
        return redirect()->route('settings.projects.edit', $project->slug);
    }
}
