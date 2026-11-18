<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Watch\Stats\CacheStats;
use App\Watch\Stats\EnvironmentFilter;
use App\Watch\Stats\TimeRange;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CacheController extends Controller
{
    public function index(Project $project, Request $request, CacheStats $stats): Response
    {
        $range = TimeRange::fromLabel($request->string('range', '24h')->toString());
        $environments = EnvironmentFilter::fromRequest($request, $project);
        $search = trim($request->string('search')->toString()) ?: null;

        return Inertia::render('projects/cache/index', [
            'summary' => $stats->summary($project, $range, $environments),
            'failures' => $stats->failures($project, $range, $environments),
            'keys' => $stats->topKeys($project, $range, $environments, $search, 100),
            'selectedRange' => $range->label,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }
}
