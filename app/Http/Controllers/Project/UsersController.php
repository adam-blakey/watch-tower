<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Watch\Stats\EnvironmentFilter;
use App\Watch\Stats\TimeRange;
use App\Watch\Stats\UserStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UsersController extends Controller
{
    public function index(Project $project, Request $request, UserStats $stats): Response
    {
        $range = TimeRange::fromLabel($request->string('range', '1h')->toString());
        $environments = EnvironmentFilter::fromRequest($request, $project);
        $search = trim($request->string('search')->toString()) ?: null;

        return Inertia::render('projects/users/index', [
            'summary' => $stats->summary($project, $range, $environments),
            'users' => $stats->list($project, $range, $environments, $search),
            'selectedRange' => $range->label,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function show(Project $project, string $user, Request $request, UserStats $stats): Response
    {
        $range = TimeRange::fromLabel($request->string('range', '1h')->toString());
        $environments = EnvironmentFilter::fromRequest($request, $project);
        $detail = $stats->detail($project, $user, $range, $environments);

        if ($detail === null) {
            throw new NotFoundHttpException('User not found for project.');
        }

        return Inertia::render('projects/users/show', [
            'user' => $detail,
            'selectedRange' => $range->label,
        ]);
    }
}
