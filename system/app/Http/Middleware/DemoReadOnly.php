<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) $request->session()->get('demo_read_only', false)) {
            $request->attributes->set('demo_read_only', true);
            $superadminVegvesen = $request->user()?->is_super_admin === true && $request->routeIs('admin.vegvesen.update', 'admin.vegvesen.delete');
            $allowed = $request->isMethodSafe() || $request->routeIs('logout', 'quotes.suggestion.preview') || $superadminVegvesen;
            if (! $allowed) {
                return back()->withErrors(['demo' => 'Dette er en skrivebeskyttet demo. Endringer og utsendelser er deaktivert.']);
            }
        }
        return $next($request);
    }
}
