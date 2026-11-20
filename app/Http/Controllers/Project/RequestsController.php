<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Watch\Stats\EnvironmentFilter;
use App\Watch\Stats\RequestStats;
use App\Watch\Stats\TimeRange;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RequestsController extends Controller
{
    public function index(Project $project, Request $request, RequestStats $stats): Response
    {
        $range = TimeRange::fromLabel($request->string('range', '1h')->toString());
        $environments = EnvironmentFilter::fromRequest($request, $project);
        $userId = $request->string('user_id')->toString() ?: null;
        $search = trim($request->string('search')->toString()) ?: null;
        $routeMethod = $request->string('route_method')->toString() ?: null;
        $routeUri = $request->string('route_uri')->toString() ?: null;

        $summary = $stats->summary($project, $range, $environments, $userId);
        $routes = $stats->routes($project, $range, $environments, $userId, $search);
        $users = $stats->topUsers($project, $range, $environments);

        $routeDetail = null;
        if ($routeMethod !== null && $routeUri !== null) {
            $routeDetail = $stats->routeDetail($project, $range, $environments, $routeMethod, $routeUri, $userId);
        }

        return Inertia::render('projects/requests/index', [
            'summary' => $summary,
            'routes' => $routes,
            'users' => $users,
            'routeDetail' => $routeDetail,
            'selectedRange' => $range->label,
            'filters' => [
                'user_id' => $userId,
                'search' => $search,
                'route_method' => $routeMethod,
                'route_uri' => $routeUri,
            ],
        ]);
    }
}
