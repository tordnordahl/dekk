<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class EnsureOrganizationOpen
{
 public function handle(Request $request, Closure $next): Response
 {
  $user=$request->user();
  if ($user && !$user->is_super_admin && !$request->routeIs('billing*','logout','login*','two-factor*')) {
   $org=\App\Models\Organization::find($user->organization_id);
   if ($org?->suspended_at) {
    if ($request->expectsJson()) return response()->json(['message'=>'Virksomhetens tilgang er stengt. Kontakt DekkPilot.'],403);
    return response()->view('superadmin.suspended',[],403);
   }
  }
  return $next($request);
 }
}
