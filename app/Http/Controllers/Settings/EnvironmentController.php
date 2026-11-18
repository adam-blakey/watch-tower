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

        $environment = $project->environments()->make($validated);
        $apiKey = $environment->issueApiKey();
        $environment->save();

        return $this->backToProject($project)
            ->with('success', "Environment '{$environment->name}' created.")
            ->with('new_api_key', $this->newApiKeyFlash("{$project->name} ({$environment->name})", $apiKey));
    }

    /**
     * Rename an environment. The slug is what agents' filters and bookmarks use, so it stays as created.
     */
    public function update(Request $request, Project $project, Environment $environment): RedirectResponse
    {
        $environment->update($request->validate(['name' => ['required', 'string', 'max:255']]));

        return $this->backToProject($project)->with('success', 'Environment updated.');
    }

    /**
     * Replace the environment's API key.
     *
     * The new key is flashed so the next page can show it once; the old key
     * stops working at once or, with a grace period, when it ends.
     */
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

    /**
     * Delete an environment other than the default.
     *
     * It is hidden at once (`deleting_at`, which drops it from the default `Environment` scope and so from lists, the
     * picker and key lookup); a queued job then deletes its telemetry and finally the row.
     */
    public function destroy(Project $project, Environment $environment): RedirectResponse
    {
        if ($environment->is_default) {
            throw ValidationException::withMessages([
                'environment' => 'The default environment cannot be deleted.',
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
