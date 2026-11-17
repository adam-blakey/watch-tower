<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Watch\Stats\DashboardStats;
use App\Watch\Stats\TimeRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Opens the user's first project, or explains why there is none to open.
     */
    public function home(Request $request): RedirectResponse|Response
    {
        $user = $request->user();

        $project = $user->isSuperAdmin()
            ? Project::query()->orderBy('created_at')->first()
            : $user->projects()->orderBy('created_at')->first();

        if (! $project) {
            return Inertia::render('home', [
                'reason' => Project::query()->exists() ? 'not_assigned' : 'no_projects',
            ]);
        }

        return redirect()->route('projects.dashboard', $project);
    }

    public function show(Project $project, Request $request, DashboardStats $stats): Response
    {
        $range = TimeRange::fromLabel($request->string('range', '1h')->toString());

        return Inertia::render('projects/dashboard', [
            'stats' => $stats->forProject($project, $range),
            'selectedRange' => $range->label,
        ]);
    }
}
