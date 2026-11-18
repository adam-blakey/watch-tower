<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Keep in step with the Caddy snippet in the Dockerfile, which sets the same value on static files.
     */
    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';

    /**
     * The CSP and COEP are skipped for the Vite dev server and Telescope, which load code inline and from
     * other origins. Redirect bodies are emptied: browsers only follow the Location header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isStrict = ! Vite::isRunningHot() && ! $request->is(config('telescope.path').'*');
        $nonce = $isStrict ? Vite::useCspNonce() : null;

        $response = $next($request);

        if ($response->isRedirection()) {
            $response->setContent('');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        if ($nonce !== null) {
            $response->headers->set('Cross-Origin-Embedder-Policy', 'require-corp');
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'nonce-{$nonce}'",
                "img-src 'self' data:",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        }

        return $response;
    }
}
