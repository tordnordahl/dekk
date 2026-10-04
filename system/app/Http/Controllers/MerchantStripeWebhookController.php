<?php
namespace App\Http\Controllers;

use App\Models\CheckoutPayment;
use App\Models\Organization;
use App\Services\MerchantPaymentSettings;
use App\Services\MerchantStripeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MerchantStripeWebhookController extends Controller
{
    public function __invoke(Request $request,Organization $organization,MerchantPaymentSettings $settings,MerchantStripeService $stripe): Response
    {
        $secret=$settings->get($organization->id,'stripe')['webhook_secret']??'';
        $parts=[];foreach(explode(',',(string)$request->header('Stripe-Signature')) as $part) {
            [$key,$value]=array_pad(explode('=',trim($part),2),2,null);$parts[$key][]=$value;
        }
        $t=(int)($parts['t'][0]??0);$expected=hash_hmac('sha256',$t.'.'.$request->getContent(),$secret);
        if (!$secret || !$t || abs(time()-$t)>300 || !collect($parts['v1']??[])->contains(fn($value)=>hash_equals($expected,(string)$value))) return response('Ugyldig signatur',400);
        $event=json_decode($request->getContent(),true);
        if (!is_array($event)) return response('Ugyldig payload',400);
        if (!in_array($event['type']??null,['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired'],true)) return response('OK');
        $id=data_get($event,'data.object.id');if(!is_string($id))return response('Ugyldig payload',400);
        $payment=CheckoutPayment::where('organization_id',$organization->id)->where('stripe_checkout_session_id',$id)->first();
        if(!$payment)return response('OK');
        try { $stripe->locked($payment,fn($p)=>$p->status==='paid'?$p:$stripe->synchronize($p)); }
        catch (\Throwable) { return response('Prøv igjen',503); }
        return response('OK');
    }
}
