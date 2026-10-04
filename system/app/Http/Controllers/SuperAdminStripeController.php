<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\StripeBillingService;
use App\Services\StripeSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class SuperAdminStripeController extends Controller
{
    public function index(StripeSettings $settings): View
    {
        $values=$settings->get();
        return view('superadmin.stripe',[
            'secretConfigured'=>filled($values['secret']??null),
            'webhookConfigured'=>filled($values['webhook_secret']??null),
            'priceId'=>$values['price_id']??'',
            'testMode'=>str_starts_with($values['secret']??'','sk_test_') || str_starts_with($values['secret']??'','rk_test_'),
            'legacyCount'=>Organization::where('billing_model','!=','stripe')->where(fn($q)=>$q->whereNull('organization_number')->orWhere('organization_number','!=','DEMO-DEKKPILOT'))->count(),
        ]);
    }

    public function save(Request $request, StripeSettings $settings, StripeBillingService $stripe): RedirectResponse
    {
        $data=$request->validate([
            'secret'=>['nullable','string','max:255','regex:/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/'],
            'price_id'=>['required','string','max:255','regex:/^price_[A-Za-z0-9]+$/'],
            'webhook_secret'=>['nullable','string','max:255','regex:/^whsec_[A-Za-z0-9]+$/'],
            'activate_all'=>['accepted'],
        ]);
        try {
            $previous=$settings->get();
            if (($previous['price_id']??null)!==$data['price_id'] && Organization::whereNotNull('stripe_subscription_id')->exists()) {
                throw new RuntimeException('Pris-ID kan ikke endres mens virksomheter har Stripe-abonnement. Behold den eksisterende månedsprisen.');
            }
            $values=$stripe->configure(array_replace($previous,array_filter($data,fn($value)=>filled($value))));
            DB::transaction(function () use ($settings,$values,$request) {
                $settings->save($values);
                Organization::where('billing_model','!=','stripe')
                    ->where(fn($q)=>$q->whereNull('organization_number')->orWhere('organization_number','!=','DEMO-DEKKPILOT'))
                    ->update(['billing_model'=>'stripe','subscription_status'=>'incomplete']);
                $this->audit($request,'stripe.configured');
            });
            return back()->with('success','Stripe er koblet til. Alle virksomheter må aktivere abonnement; superadmin og demo beholder tilgang.');
        } catch (RuntimeException $e) {
            // Never flash API keys back into the session or rendered input fields.
            return back()->withErrors(['stripe'=>$e->getMessage()]);
        }
    }

    public function freeMonth(Request $request, Organization $organization, StripeBillingService $stripe): RedirectResponse
    {
        $data=$request->validate(['grant_key'=>['required','uuid'],'confirm'=>['accepted']]);
        try {
            $stripe->grantFreeMonth($organization,$data['grant_key']);
            $this->audit($request,'stripe.free_month_granted',$organization,['grant_key'=>$data['grant_key']]);
            return back()->with('success','Én gratis abonnementsmåned er tildelt '.$organization->name.'. Gjelder første eller neste månedsbetaling, ikke SMS.');
        } catch (LockTimeoutException) { return back()->withErrors(['stripe'=>'En betalingsoppdatering pågår. Prøv igjen om litt.']); }
        catch (RuntimeException $e) { return back()->withErrors(['stripe'=>$e->getMessage()]); }
    }

    public function refresh(Request $request, Organization $organization, StripeBillingService $stripe): RedirectResponse
    {
        try {
            $stripe->refresh($organization);
            return back()->with('success','Abonnementsstatus er oppdatert fra Stripe.');
        } catch (LockTimeoutException) { return back()->withErrors(['stripe'=>'En betalingsoppdatering pågår. Prøv igjen om litt.']); }
        catch (RuntimeException $e) { return back()->withErrors(['stripe'=>$e->getMessage()]); }
    }

    private function audit(Request $request,string $action,?Organization $org=null,array $metadata=[]): void
    {
        DB::table('audit_logs')->insert(['organization_id'=>$org?->id??$request->user()->organization_id,'user_id'=>$request->user()->id,
            'action'=>$action,'subject_type'=>$org?Organization::class:null,'subject_id'=>$org?->id,'ip_address'=>$request->ip(),
            'metadata'=>json_encode($metadata),'created_at'=>now()]);
    }
}
