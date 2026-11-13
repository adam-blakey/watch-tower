<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIngestRequest
{
    /**
     * When the `X-API-Key` header was deprecated (2026-11-13), as an RFC 9745 `Deprecation` date.
     *
     * Custom headers are more likely than `Authorization` to be written to proxy and access logs. The header is
     * still accepted so existing agents keep working, but responses to it carry `Deprecation` so they can move.
     */
    public const LEGACY_API_KEY_HEADER_DEPRECATION = '@1794528000';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $usesLegacyHeader = $request->bearerToken() === null;
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

        if ($usesLegacyHeader) {
            $response->headers->set('Deprecation', self::LEGACY_API_KEY_HEADER_DEPRECATION);
        }

        return $response;
    }
}
