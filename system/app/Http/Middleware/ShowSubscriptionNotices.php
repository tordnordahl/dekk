<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ShowSubscriptionNotices
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->session()->get('billing_notice_login') || !$request->isMethod('GET')
            || $request->expectsJson() || $request->user()->is_super_admin || $request->session()->has('superadmin_tenant_id')) {
            return $next($request);
        }
        return DB::transaction(function () use ($request,$next) {
            $notices=DB::table('subscription_notices')->where('user_id',$request->user()->id)
                ->where('organization_id',$request->user()->organization_id)->whereNull('seen_at')->lockForUpdate()->get();
            $request->attributes->set('subscription_notices',$notices);
            $response=$next($request);
            // A redirect, 2FA challenge, failed page or JSON response must not consume a notice.
            if ($response->getStatusCode()===200 && $request->attributes->get('subscription_notices_rendered')) {
                if ($notices->isNotEmpty()) DB::table('subscription_notices')->whereIn('id',$notices->pluck('id'))->update(['seen_at'=>now()]);
                $request->session()->forget('billing_notice_login');
            }
            return $response;
        });
    }
}
