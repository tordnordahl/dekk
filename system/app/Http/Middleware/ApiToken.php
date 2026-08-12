<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ApiToken
{
    public function handle(Request $request, Closure $next, ?string $requiredAbility = null): Response
    {
        $plain = $request->bearerToken();
        if (!$plain) return response()->json(['message' => 'Unauthenticated.'], 401);

        $token = DB::table('personal_access_tokens')->where('token_hash', hash('sha256', $plain))->first();
        if (!$token || $token->revoked_at || ($token->expires_at && now()->isAfter($token->expires_at))) {
            return response()->json(['message' => 'Invalid or expired token.'], 401);
        }

        $user = User::whereKey($token->user_id)->where('active', true)->first();
        if (!$user) return response()->json(['message' => 'Unauthenticated.'], 401);

        auth()->setUser($user);
        $abilities = json_decode($token->abilities ?: '[]', true) ?: [];
        if ($requiredAbility && !in_array('*', $abilities, true) && !in_array($requiredAbility, $abilities, true)) {
            return response()->json(['message' => 'Tokenet mangler nødvendig tilgang: '.$requiredAbility], 403);
        }
        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_abilities', $abilities);
        DB::table('personal_access_tokens')->where('id', $token->id)->update(['last_used_at' => now()]);
        return $next($request);
    }
}
