<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\StripeBillingService;
use App\Services\StripeSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class StripeWebhookController extends Controller
{
    public const EVENTS = ['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed',
        'customer.subscription.created','customer.subscription.updated','customer.subscription.deleted','invoice.paid','invoice.payment_failed'];

    public function __invoke(Request $request, StripeBillingService $stripe, StripeSettings $settings): Response
    {
        $payload=$request->getContent();
        if (!$this->valid($payload,(string)$request->header('Stripe-Signature'),(string)($settings->get()['webhook_secret']??''))) return response('Ugyldig signatur',400);
        $event=json_decode($payload,true);
        if (!is_array($event) || !is_string($event['id']??null) || !is_string($event['type']??null)) return response('Ugyldig payload',400);
        if (!in_array($event['type'],self::EVENTS,true) || DB::table('billing_webhook_events')->where('event_id',$event['id'])->exists()) return response('OK');
        $object=$event['data']['object']??[];
        if (!is_array($object)) return response('Ugyldig payload',400);
        // Resolve only by the customer linked server-side, never by arbitrary invoice metadata.
        $customer=$object['customer']??null;
        if (!is_string($customer) || $customer==='') return response('OK');
        $org=Organization::where('stripe_customer_id',$customer)->first();
        if (!$org) return response('OK');
        try {
            $stripe->locked($org,function(Organization $org) use ($stripe,$event,$object) {
                if (DB::table('billing_webhook_events')->where('event_id',$event['id'])->exists()) return;
                DB::transaction(function () use ($stripe,$org,$event,$object) {
                    if (str_starts_with($event['type'],'checkout.session.')) {
                        if (($object['id']??null)===$org->stripe_checkout_session_id) $stripe->syncSession($org,$stripe->retrieveSession($object['id']));
                    } else {
                        $id=str_starts_with($event['type'],'customer.subscription.') ? ($object['id']??null)
                            : ($object['subscription']??data_get($object,'parent.subscription_details.subscription'));
                        // Old invoices/cancellations must never resurrect or cancel a replacement subscription.
                        if (is_string($id) && ($id===$org->stripe_subscription_id
                            || (!$org->stripe_subscription_id && (string)data_get($object,'metadata.organization_id')===(string)$org->id))) {
                            $stripe->syncSubscription($org,$id);
                        }
                    }
                    DB::table('billing_webhook_events')->insertOrIgnore(['provider'=>'stripe','event_id'=>$event['id'],'event_type'=>$event['type'],'processed_at'=>now()]);
                });
            });
        } catch (\Throwable $e) {
            // Stripe retries failures. Do not log API responses, signatures or customer data.
            \Illuminate\Support\Facades\Log::warning('Stripe webhook retry required',['event_id'=>$event['id'],'exception'=>get_class($e)]);
            return response('Prøv igjen',503);
        }
        return response('OK');
    }

    private function valid(string $payload,string $header,string $secret): bool
    {
        if ($secret==='' || $header==='') return false;
        $parts=[];
        foreach(explode(',',$header) as $item) { [$key,$value]=array_pad(explode('=',trim($item),2),2,null); $parts[$key][]=$value; }
        $timestamp=(int)($parts['t'][0]??0);
        if (!$timestamp || abs(time()-$timestamp)>300) return false;
        $expected=hash_hmac('sha256',$timestamp.'.'.$payload,$secret);
        foreach($parts['v1']??[] as $signature) if(hash_equals($expected,(string)$signature)) return true;
        return false;
    }
}
