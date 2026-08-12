<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class RoleOnly{public function handle(Request $request,Closure $next,string ...$roles):Response{$user=$request->user();abort_unless($user&&($user->is_super_admin||in_array($user->role,$roles,true)),403,'Rollen din har ikke tilgang til denne handlingen.');return $next($request);}}
