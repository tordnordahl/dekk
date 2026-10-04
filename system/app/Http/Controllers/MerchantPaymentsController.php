<?php
namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\CheckoutPayment;
use App\Services\MerchantPaymentSettings;
use App\Services\MerchantStripeService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class MerchantPaymentsController extends Controller
{
    public function index(Request $request, MerchantPaymentSettings $settings): View
    {
        $org=Organization::findOrFail($request->user()->organization_id);
        $stripe=$settings->get($org->id,'stripe');$vipps=$settings->get($org->id,'vipps');
        return view('admin.payments',[
            'organization'=>$org,
            'stripe'=>array_intersect_key($stripe,array_flip(['active','account_id','account_name','live','verified_at']))+['key_saved'=>filled($stripe['secret']??null)],
            'zettle'=>$settings->get($org->id,'zettle'),
            'terminal'=>$settings->get($org->id,'terminal'),
            'vipps'=>array_intersect_key($vipps,array_flip(['active','client_id','msn','test']))+['secret_saved'=>filled($vipps['client_secret']??null),'key_saved'=>filled($vipps['subscription_key']??null)],
        ]);
    }

    public function stripe(Request $request, MerchantPaymentSettings $settings, MerchantStripeService $stripe): RedirectResponse
    {
        $data=$request->validate(['merchant_secret'=>['nullable','string','max:255','regex:/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/'],
            'merchant_webhook_secret'=>['nullable','string','max:255','regex:/^whsec_[A-Za-z0-9]+$/'],'active'=>['nullable','boolean']]);
        $org=Organization::findOrFail($request->user()->organization_id);$old=$settings->get($org->id,'stripe');
        try {
            if (blank($data['merchant_secret']??null) && !$request->boolean('active') && filled($old['secret']??null)) {
                $settings->save($org->id,'stripe',$old,false,$request->user()->id);
                return back()->with('success','Nye Stripe-betalinger er slått av. Påbegynte betalinger kan fortsatt bekreftes.');
            }
            $config=$old;
            if (filled($data['merchant_secret']??null)) $config['secret']=$data['merchant_secret'];
            if (filled($data['merchant_webhook_secret']??null)) $config['webhook_secret']=$data['merchant_webhook_secret'];
            $config=$stripe->configure($org,$config,$request->boolean('active'));
            $settings->save($org->id,'stripe',$config,$request->boolean('active'),$request->user()->id);
            $this->audit($request,'merchant.stripe.configured');
            return back()->with('success',$request->boolean('active')?'Stripe er kontrollert og aktivert for virksomhetens egne kunder.':'Tilkoblingen er kontrollert og lagret. Kundebetaling er ikke aktivert.');
        } catch (RuntimeException $e) { return back()->withErrors(['payment'=>$e->getMessage()]); }
    }

    public function zettle(Request $request, MerchantPaymentSettings $settings): RedirectResponse
    {
        $data=$request->validate(['name'=>['required','string','max:100'],'active'=>['nullable','boolean'],'manual_confirmation'=>['accepted']]);
        $settings->save($request->user()->organization_id,'zettle',['name'=>$data['name'],'mode'=>'manual'], $request->boolean('active'),$request->user()->id);
        $this->audit($request,'merchant.zettle.configured');
        return back()->with('success','Zettle-oppsettet er lagret. Betalingen gjøres i Zettle-appen og bekreftes av en ansatt med kvitteringsreferansen.');
    }

    public function confirmZettle(Request $request, CheckoutPayment $payment, MerchantPaymentSettings $settings, MerchantStripeService $stripe): RedirectResponse
    {
        abort_unless($request->user()->organization_id===$payment->organization_id,404);
        $data=$request->validate(['provider_reference'=>['required','string','max:255'],'confirmed_amount'=>['required','numeric','decimal:0,2','min:0.01'],'confirm'=>['accepted']]);
        try {
            $stripe->locked($payment,function(CheckoutPayment $payment) use ($request,$data,$settings) {
                if (!($settings->get($payment->organization_id,'zettle')['active']??false)) throw new RuntimeException('Zettle er ikke aktivert.');
                if ($payment->status==='paid') return;
                if ($payment->stripe_checkout_key || $payment->payment_method!=='zettle' || $payment->status!=='processing'
                    || $payment->invoiced_at || $payment->invoiceExport->status==='exported') throw new RuntimeException('Betalingen kan ikke bekreftes som Zettle i denne statusen.');
                if ((int)round((float)$data['confirmed_amount']*100)!==(int)$payment->amount_cents) throw new RuntimeException('Beløpet på kvitteringen må stemme med beløpet i DekkPilot.');
                app(\App\Services\CheckoutPaymentService::class)->complete($payment,'zettle',$data['provider_reference'],'STAFF_CONFIRMED');
                $this->audit($request,'merchant.zettle.payment_confirmed',['payment_id'=>$payment->id]);
            });
            return back()->with('success','Godkjent Zettle-betaling er registrert. Kvitteringen er klar.');
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) { return back()->withErrors(['payment'=>'Betalingen oppdateres. Prøv igjen om litt.']); }
        catch (RuntimeException $e) { return back()->withErrors(['payment'=>$e->getMessage()]); }
    }

    public function cancelZettle(Request $request, CheckoutPayment $payment, MerchantStripeService $stripe): RedirectResponse
    {
        abort_unless($request->user()->organization_id===$payment->organization_id,404);
        $request->validate(['confirm_not_paid'=>['accepted']]);
        try {
            $stripe->locked($payment,function($payment) use($request) {
                if ($payment->status!=='processing' || $payment->payment_method!=='zettle' || $payment->stripe_checkout_key) throw new RuntimeException('Ingen Zettle-betaling venter på avklaring.');
                $payment->update(['status'=>'pending','payment_method'=>null,'provider'=>'none','provider_status'=>null]);
                $this->audit($request,'merchant.zettle.payment_not_received',['payment_id'=>$payment->id]);
            });
            return back()->with('success','Zettle-forsøket er avbrutt. Velg ny betalingsmåte.');
        } catch (\RuntimeException $e) { return back()->withErrors(['payment'=>$e->getMessage()]); }
    }

    private function audit(Request $request,string $action,array $metadata=[]): void
    {
        DB::table('audit_logs')->insert(['organization_id'=>$request->user()->organization_id,'user_id'=>$request->user()->id,
            'action'=>$action,'metadata'=>json_encode($metadata),'ip_address'=>$request->ip(),'created_at'=>now()]);
    }
}
