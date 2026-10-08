<?php
namespace App\Http\Middleware;
use App\Services\ServiceAgreements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class EnsureAgreementAccepted
{
 public function handle(Request $request, Closure $next): Response {
  if ($request->routeIs('logout') || !$request->user() || $request->user()->is_super_admin || !in_array($request->user()->role,['owner','admin'],true)) return $next($request);
  $service=app(ServiceAgreements::class);
  if ($service->needsAcceptance($request->user(),$service->current())) {
   if ($request->expectsJson()) return response()->json(['message'=>'Eier eller administrator må godta den oppdaterte avtalen.','redirect'=>route('agreement.required')],423);
   return redirect()->route('agreement.required');
  }
  return $next($request);
 }
}
