<?php

namespace App\Http\Middleware;

use App\Models\Environment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIngestRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->bearerToken() ?? $request->header('X-API-Key');

        if (! $apiKey) {
            return response()->json([
                'error' => 'Missing API key. Provide it via Authorization: Bearer header or X-API-Key.',
            ], 401);
        }

        $environment = Environment::findByApiKey($apiKey);

        if (! $environment) {
            return response()->json(['error' => 'Invalid API key.'], 401);
        }

        $environment->recordApiKeyUse();

        $request->attributes->set('watch_environment', $environment);
        $request->attributes->set('watch_project', $environment->project);

        return $next($request);
    }
}
