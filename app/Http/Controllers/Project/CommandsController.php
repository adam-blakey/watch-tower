<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Watch\Stats\CommandStats;
use App\Watch\Stats\EnvironmentFilter;
use App\Watch\Stats\TimeRange;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommandsController extends Controller
{
    public function index(Project $project, Request $request, CommandStats $stats): Response
    {
        $range = TimeRange::fromLabel($request->string('range', '1h')->toString());
        $environments = EnvironmentFilter::fromRequest($request, $project);
        $search = trim($request->string('search')->toString()) ?: null;
        $sort = $request->string('sort')->toString() ?: null;
        $dir = $request->string('dir')->toString() ?: null;
        $page = max(1, (int) $request->integer('page', 1));

        return Inertia::render('projects/commands/index', [
            'summary' => $stats->summary($project, $range, $environments),
            'commands' => $stats->paginatedCommands($project, $range, $environments, $search, $sort, $dir, $page, 25),
            'selectedRange' => $range->label,
            'filters' => [
                'search' => $search,
                'sort' => $sort ?? 'command',
                'dir' => $dir ?? 'asc',
            ],
        ]);
    }

    public function show(Project $project, Request $request, CommandStats $stats, string $command): Response
    {
        $range = TimeRange::fromLabel($request->string('range', '30d')->toString());
        $environments = EnvironmentFilter::fromRequest($request, $project);

        $detail = $stats->commandDetail($project, $range, $environments, $command);

        abort_if($detail === null, 404);

        return Inertia::render('projects/commands/show', [
            'detail' => $detail,
            'selectedRange' => $range->label,
        ]);
    }
}
