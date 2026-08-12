<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_super_admin === true || $request->routeIs('billing*', 'logout')) return $next($request);

        $org = Organization::find($request->user()?->organization_id);
        if (! $org || ! in_array($org->subscription_status, ['active', 'trialing', 'past_due'], true)) {
            if ($request->expectsJson() || $request->is('api/*')) return response()->json(['message' => 'Virksomheten trenger et aktivt abonnement.'], 402);
            return redirect()->route('billing')->withErrors(['subscription' => 'Virksomheten trenger et aktivt abonnement for å bruke DekkPilot.']);
        }
        return $next($request);
    }
}
