<?php

namespace App\Http\Controllers;

use App\Models\BillingStatement;
use App\Models\Organization;
use App\Services\StripeBillingService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class BillingController extends Controller
{
    public function index(Request $request, StripeBillingService $stripe): View
    {
        return view('billing.index', [
            'organization'=>Organization::findOrFail($request->user()->organization_id),
            'stripeReady'=>$stripe->ready(),
            'smsUsage'=>DB::table('usage_events')->where('organization_id',$request->user()->organization_id)->where('type','sms')->where('occurred_at','>=',now()->startOfMonth())->sum('quantity'),
            'statements'=>BillingStatement::where('organization_id',$request->user()->organization_id)->latest('period_start')->limit(24)->get(),
        ]);
    }

    public function activateFreeAccess(Request $request, StripeBillingService $stripe): RedirectResponse
    {
        $data=$request->validate(['grant_key'=>['required','uuid'],'confirm'=>['accepted']]);
        return $this->run(function() use($request,$stripe,$data) {
            $org=$this->organization($request);
            $stripe->activateFreeAccess($org,$data['grant_key']);
            DB::table('audit_logs')->insert(['organization_id'=>$org->id,'user_id'=>$request->user()->id,'action'=>'billing.free_access.activated','metadata'=>json_encode(['grant_key'=>$data['grant_key']]),'created_at'=>now()]);
            return redirect()->route('dashboard')->with('success','Gratisperioden er aktiv til '.$org->fresh()->free_access_until->timezone('Europe/Oslo')->format('d.m.Y H:i').'. Du blir ikke belastet automatisk. Etter perioden må Stripe-abonnement aktiveres.');
        });
    }

    public function checkout(Request $request, StripeBillingService $stripe): RedirectResponse
    {
        $request->validate(['accept_subscription'=>['accepted']]);
        return $this->run(fn()=>redirect()->away($stripe->checkout($this->organization($request))));
    }

    public function portal(Request $request, StripeBillingService $stripe): RedirectResponse
    {
        return $this->run(fn()=>redirect()->away($stripe->portal($this->organization($request))));
    }

    public function success(Request $request, StripeBillingService $stripe): RedirectResponse
    {
        $data=$request->validate(['session_id'=>['required','string','max:255','regex:/^cs_[A-Za-z0-9_]+$/']]);
        return $this->synchronize($request,$stripe,$data['session_id']);
    }

    public function refresh(Request $request, StripeBillingService $stripe): RedirectResponse
    {
        return $this->synchronize($request,$stripe);
    }

    private function synchronize(Request $request, StripeBillingService $stripe, ?string $sessionId=null): RedirectResponse
    {
        return $this->run(function () use ($request,$stripe,$sessionId) {
            $org=$this->organization($request);
            $stripe->refresh($org,$sessionId);
            return redirect()->route('billing')->with('success',$org->fresh()->hasSubscriptionAccess()
                ? 'Abonnementet er aktivt. Du har tilgang til DekkPilot.'
                : 'Status er oppdatert. Abonnementet må aktiveres eller betalingen fullføres hos Stripe.');
        });
    }

    private function organization(Request $request): Organization
    {
        return Organization::findOrFail($request->user()->organization_id);
    }

    private function run(callable $callback): RedirectResponse
    {
        try { return $callback(); }
        catch (LockTimeoutException) { return redirect()->route('billing')->withErrors(['stripe'=>'En betalingsoppdatering pågår. Prøv igjen om litt.']); }
        catch (RuntimeException $e) { return redirect()->route('billing')->withErrors(['stripe'=>$e->getMessage()]); }
    }
}
