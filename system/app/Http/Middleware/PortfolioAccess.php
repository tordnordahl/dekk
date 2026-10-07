<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PortfolioAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('portfolio.enabled')) {
            return $this->error('NOT_FOUND', 404);
        }

        $local = app()->environment('local', 'testing')
            && in_array($request->ip(), ['127.0.0.1', '::1'], true);
        if (! $request->isSecure() && ! $local) {
            return $this->error('HTTPS_REQUIRED', 403);
        }

        $hash = (string) config('portfolio.token_hash');
        $token = $request->bearerToken() ?? '';
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash)
            || ! preg_match('/^[a-f0-9]{64}$/D', $token)
            || ! hash_equals($hash, hash('sha256', $token))) {
            return $this->error('UNAUTHENTICATED', 401);
        }

        $publicKey = base64_decode((string) config('portfolio.recipient_public_key'), true);
        if (! function_exists('sodium_crypto_box_seal')
            || $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return $this->error('ENCRYPTION_NOT_CONFIGURED', 503);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }

    private function error(string $code, int $status): Response
    {
        return response()->json(['error' => $code], $status, [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
