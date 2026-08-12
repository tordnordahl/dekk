<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $mayFrame = $request->routeIs('tire-sets.labels', 'admin.communications.message-preview');
        $response->headers->set('X-Frame-Options', $mayFrame ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), geolocation=(), microphone=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $frameAncestors = $mayFrame ? "'self'" : "'none'";
        $policy = "default-src 'self'; object-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self'; frame-src 'self'; worker-src 'none'; manifest-src 'self'; base-uri 'self'; frame-ancestors {$frameAncestors}; form-action 'self'";
        if (app()->environment('production') && $request->isSecure()) {
            $policy .= '; upgrade-insecure-requests';
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $response->headers->set('Content-Security-Policy', $policy);
        if ($request->user() || $request->routeIs('login', 'password.*', 'quote.public.*', 'booking.confirm.*', 'portal.*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }
        return $response;
    }
}
