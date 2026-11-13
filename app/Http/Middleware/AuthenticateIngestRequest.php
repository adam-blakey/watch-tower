<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIngestRequest
{
    // 2026-11-13 as an RFC 9745 Deprecation date.
    public const X_API_KEY_HEADER_DEPRECATED_SINCE = '@1794528000';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $usesXApiKeyHeader = $request->bearerToken() === null;
        $apiKey = $request->bearerToken() ?? $request->header('X-API-Key');

        if (! $apiKey) {
            return response()->json([
                'error' => 'Missing API key. Provide it via the Authorization: Bearer header.',
            ], 401);
        }

        $project = Project::findByApiKey($apiKey);

        if (! $project) {
            return response()->json(['error' => 'Invalid API key.'], 401);
        }

        $project->recordApiKeyUse();

        $request->attributes->set('watch_project', $project);

        $response = $next($request);

        if ($usesXApiKeyHeader) {
            $response->headers->set('Deprecation', self::X_API_KEY_HEADER_DEPRECATED_SINCE);
        }

        return $response;
    }
}
