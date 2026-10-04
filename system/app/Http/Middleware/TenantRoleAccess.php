<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantRoleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($request->routeIs('billing')) return $next($request);
        if (! $user || $user->is_super_admin || ! in_array($user->role, ['technician', 'warehouse'], true)) {
            return $next($request);
        }

        $allowed = $user->role === 'technician'
            ? ['dashboard', 'help', 'logout', 'actions*', 'workday*', 'warehouse.map', 'inventory*', 'tire-sets*', 'work-orders*', 'ui-mode*']
            : ['dashboard', 'help', 'logout', 'actions*', 'workday*', 'warehouse.map', 'inventory*', 'tire-sets*', 'hotel-agreements*', 'ui-mode*'];

        $readOnlyTimebook = $user->role === 'technician'
            && $request->isMethodSafe()
            && $request->routeIs('bookings');
        abort_unless($readOnlyTimebook || $request->routeIs(...$allowed), 403, 'Rollen din har ikke tilgang til denne delen av DekkPilot.');

        return $next($request);
    }
}
