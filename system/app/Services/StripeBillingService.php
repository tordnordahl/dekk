<?php

namespace App\Services;

use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class StripeBillingService
{
    public function __construct(private readonly StripeSettings $settings) {}

    public function locked(Organization $org, callable $callback): mixed
    {
        return Cache::lock('stripe:organization:'.$org->id, 180)->block(5, function () use ($org, $callback) {
            return $callback($org->fresh());
        });
    }

    public function ready(): bool
    {
        $settings = $this->settings->get();
        return filled($settings['secret'] ?? null) && filled($settings['price_id'] ?? null) && filled($settings['webhook_secret'] ?? null);
    }

    public function validatePrice(?array $settings = null): array
    {
        $settings ??= $this->settings->get();
        $price = $this->request('get', '/prices/'.rawurlencode($settings['price_id'] ?? ''), [], null, $settings);
        if (!($price['active'] ?? false) || ($price['currency'] ?? '') !== 'nok'
            || ($price['unit_amount'] ?? 0) !== 24900 || data_get($price, 'recurring.interval') !== 'month'
            || data_get($price, 'recurring.interval_count') !== 1 || ($price['tax_behavior'] ?? '') === 'exclusive') {
            throw new RuntimeException('Stripe-prisen må være aktiv, 249 NOK per måned og ikke legge mva. oppå prisen.');
        }
        return $price;
    }

    public function configure(array $settings): array
    {
        if (app()->environment('production') && !preg_match('/^(sk|rk)_live_/', $settings['secret'] ?? '')) throw new RuntimeException('Produksjon krever en live-nøkkel fra Stripe.');
        $this->validatePrice($settings);
        if (empty($settings['portal_configuration_id'])) {
            $portal=$this->request('post','/billing_portal/configurations',[
                'business_profile'=>['headline'=>'Administrer DekkPilot-abonnementet ditt'],
                'features'=>[
                    'payment_method_update'=>['enabled'=>'true'],
                    'invoice_history'=>['enabled'=>'true'],
                    'customer_update'=>['enabled'=>'true','allowed_updates'=>['email','address','tax_id']],
                    'subscription_cancel'=>['enabled'=>'true','mode'=>'at_period_end'],
                ],
            ],'dekkpilot-portal-v1', $settings);
            $settings['portal_configuration_id']=$portal['id'];
        }
        if (empty($settings['webhook_secret'])) {
            $url=route('webhooks.stripe');
            if (!str_starts_with($url,'https://') && !app()->environment('testing')) throw new RuntimeException('Stripe-webhooken krever en offentlig HTTPS-adresse.');
            $endpoint=$this->request('post','/webhook_endpoints',[
                'url'=>$url,'enabled_events'=>\App\Http\Controllers\StripeWebhookController::EVENTS,
                'api_version'=>'2025-06-30.basil',
            ],'dekkpilot-webhook-'.hash('sha256',$url),$settings);
            $settings['webhook_secret']=$endpoint['secret'];
            $settings['webhook_endpoint_id']=$endpoint['id'];
        }
        return $settings;
    }

    public function checkout(Organization $org): string
    {
        return $this->locked($org, function (Organization $org) {
            if ($org->suspended_at) throw new RuntimeException('Tilgangen er stengt av DekkPilot. Kontakt systemeier før du starter abonnement.');
            if ($org->hasFreeAccess()) throw new RuntimeException('Gratisperioden varer til '.$org->free_access_until->timezone('Europe/Oslo')->format('d.m.Y H:i').'. Aktiver Stripe når perioden er over.');
            if (!$this->ready()) throw new RuntimeException('Stripe er ikke ferdig konfigurert. Kontakt systemeier.');
            if ($org->organization_number === 'DEMO-DEKKPILOT') throw new RuntimeException('Demoen kan ikke faktureres.');
            $price = $this->validatePrice();
            if (!$org->stripe_customer_id) {
                $customer = $this->request('post', '/customers', [
                    'name'=>$org->name, 'email'=>$org->email, 'metadata'=>['organization_id'=>(string)$org->id],
                ], 'dekkpilot-customer-'.$org->public_id);
                $org->update(['stripe_customer_id'=>$customer['id']]);
            }
            // Recover subscriptions even when the browser closed before the return/webhook arrived.
            $subscriptions = $this->request('get', '/subscriptions', ['customer'=>$org->stripe_customer_id, 'status'=>'all', 'limit'=>100]);
            foreach ($subscriptions['data'] ?? [] as $subscription) {
                if (!in_array($subscription['status'], ['canceled', 'incomplete_expired'], true)) {
                    $this->applySubscription($org, $subscription);
                    throw new RuntimeException('Virksomheten har allerede et abonnement. Bruk «Administrer hos Stripe» eller «Oppdater status».');
                }
            }
            if ($subscriptions['has_more'] ?? false) throw new RuntimeException('Abonnementene må kontrolleres av systemeier før et nytt opprettes.');
            if ($org->stripe_checkout_session_id) {
                $session = $this->retrieveSession($org->stripe_checkout_session_id);
                if (($session['status'] ?? '') === 'open') return $session['url'];
                if (($session['status'] ?? '') === 'complete') {
                    $this->syncSession($org, $session);
                    if (!in_array($org->subscription_status,['canceled','incomplete_expired'],true)) throw new RuntimeException('Betalingen er mottatt. Oppdater abonnementsstatus før du fortsetter.');
                }
                $org->update(['stripe_checkout_key'=>null, 'stripe_checkout_session_id'=>null]);
            }
            if (!$org->stripe_checkout_key) $org->update(['stripe_checkout_key'=>(string)Str::uuid()]);
            $data = [
                'mode'=>'subscription', 'customer'=>$org->stripe_customer_id,
                'line_items'=>[['price'=>$price['id'], 'quantity'=>1]],
                'payment_method_types'=>['card'], 'payment_method_collection'=>'always',
                'success_url'=>route('billing.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'=>route('billing'), 'client_reference_id'=>(string)$org->id,
                'metadata'=>['organization_id'=>(string)$org->id],
                'subscription_data'=>['metadata'=>['organization_id'=>(string)$org->id]],
            ];
            if ($org->hasUnusedFreeGrant()) {
                $data['discounts'] = [['coupon'=>$this->coupon($org, $price)]];
                $data['subscription_data']['metadata']['free_month_key'] = $org->stripe_free_month_key;
            } else {
                $data['allow_promotion_codes'] = true;
            }
            $session = $this->request('post', '/checkout/sessions', $data, 'dekkpilot-checkout-'.$org->stripe_checkout_key);
            $org->update(['stripe_checkout_session_id'=>$session['id']]);
            return $session['url'];
        });
    }

    public function portal(Organization $org): string
    {
        if (!$org->stripe_customer_id) throw new RuntimeException('Start abonnementet før du åpner Stripe-portalen.');
        return $this->request('post', '/billing_portal/sessions', array_filter(['customer'=>$org->stripe_customer_id, 'return_url'=>route('billing.portal-return'), 'configuration'=>$this->settings->get()['portal_configuration_id']??null]))['url'];
    }

    public function retrieveSession(string $id): array
    {
        return $this->request('get', '/checkout/sessions/'.rawurlencode($id));
    }

    public function syncSession(Organization $org, array $session): void
    {
        if (($session['mode'] ?? '') !== 'subscription' || (string)($session['client_reference_id'] ?? '') !== (string)$org->id
            || ($session['customer'] ?? null) !== $org->stripe_customer_id
            || ($session['id'] ?? null) !== $org->stripe_checkout_session_id) {
            throw new RuntimeException('Betalingen tilhører ikke denne virksomheten.');
        }
        if (($session['status'] ?? '') !== 'complete' || empty($session['subscription'])) return;
        $this->syncSubscription($org, $session['subscription']);
    }

    public function refresh(Organization $org, ?string $sessionId = null): void
    {
        $this->locked($org, function (Organization $org) use ($sessionId) {
            if ($sessionId) $this->syncSession($org, $this->retrieveSession($sessionId));
            elseif ($org->stripe_subscription_id) $this->syncSubscription($org, $org->stripe_subscription_id);
            elseif ($org->stripe_checkout_session_id) $this->syncSession($org, $this->retrieveSession($org->stripe_checkout_session_id));
        });
    }

    public function syncSubscription(Organization $org, string $id): void
    {
        $this->applySubscription($org, $this->request('get', '/subscriptions/'.rawurlencode($id), ['expand'=>['latest_invoice']]));
    }

    private function applySubscription(Organization $org, array $subscription): void
    {
        if (($subscription['customer'] ?? null) !== $org->stripe_customer_id
            || (string)data_get($subscription,'metadata.organization_id') !== (string)$org->id) {
            throw new RuntimeException('Stripe-abonnementet tilhører ikke denne virksomheten.');
        }
        $priceId = $this->settings->get()['price_id'];
        $item = collect(data_get($subscription, 'items.data', []))->firstWhere('price.id', $priceId);
        if (!$item || count(data_get($subscription, 'items.data', [])) !== 1 || ($item['quantity'] ?? 0) !== 1) {
            throw new RuntimeException('Stripe-abonnementet har ikke forventet DekkPilot-pris.');
        }
        $end = $subscription['current_period_end'] ?? $item['current_period_end'] ?? null;
        $status = $subscription['status'];
        if (!empty($subscription['pause_collection'])) $status = 'paused';
        $updates = [
            'billing_model'=>'stripe', 'stripe_subscription_id'=>$subscription['id'],
            'subscription_status'=>$status, 'subscription_ends_at'=>$end ? Carbon::createFromTimestampUTC($end) : null,
            'stripe_cancel_at_period_end'=>(bool)($subscription['cancel_at_period_end'] ?? false),
        ];
        if ($org->stripe_free_month_key && data_get($subscription,'metadata.free_month_key') === $org->stripe_free_month_key
            && in_array($status, ['active','trialing'], true) && $org->hasUnusedFreeGrant()) {
            $updates['stripe_free_month_applied_at'] = now();
        }
        $updates['stripe_synced_at']=now();
        $invoice=$subscription['latest_invoice']??null;
        if (is_array($invoice) && ($invoice['customer']??null)===$org->stripe_customer_id) {
            $updates['stripe_latest_invoice']=array_intersect_key($invoice,array_flip(['id','number','status','amount_due','amount_paid','amount_remaining','currency','created','status_transitions']));
        } elseif (is_string($invoice) && data_get($org->stripe_latest_invoice,'id')!==$invoice) {
            $updates['stripe_latest_invoice']=null;
        }
        $org->update($updates);
    }

    public function grantFreeMonth(Organization $org, string $key, int $months=1): void
    {
        if ($months<1 || $months>12) throw new RuntimeException('Velg mellom 1 og 12 måneder.');
        $this->locked($org, function (Organization $org) use ($key,$months) {
            if ($org->stripe_free_month_key===$key && (int)$org->stripe_free_month_count!==$months) throw new RuntimeException('Denne tildelingen har allerede et annet antall måneder. Last siden på nytt.');
            if ($org->organization_number === 'DEMO-DEKKPILOT') throw new RuntimeException('Demoen trenger ikke abonnement.');
            if ($org->stripe_free_month_key === $key && ($org->stripe_free_month_applied_at || $org->free_access_grant_key === $key)) { $this->queueNotices($org); return; }
            if ($org->hasUnusedFreeGrant() && $org->stripe_free_month_key !== $key) {
                throw new RuntimeException('Virksomheten har allerede en gratismåned som venter på aktivering.');
            }
            $subscription = null;
            if ($org->stripe_subscription_id) {
                $subscription = $this->request('get', '/subscriptions/'.rawurlencode($org->stripe_subscription_id));
                $this->applySubscription($org, $subscription);
                if (in_array($subscription['status'], ['canceled','incomplete_expired'], true)) $subscription = null;
                elseif (!in_array($subscription['status'], ['active','trialing'], true)) throw new RuntimeException('Utestående betaling må avklares før en ny gratismåned gis.');
                elseif (!empty($subscription['discounts']) && data_get($subscription,'metadata.free_month_key') !== $key) {
                    throw new RuntimeException('Abonnementet har allerede rabatt. Vent til den er brukt før du gir neste gratismåned.');
                }
            }
            if ($org->stripe_checkout_key && !$org->stripe_checkout_session_id) throw new RuntimeException('Et betalingsforsøk må gjenopptas fra abonnementssiden før rabatten kan endres.');
            // Expire an older checkout so it cannot bypass the newly assigned discount.
            if ($org->stripe_checkout_session_id && !$subscription) {
                $session=$this->retrieveSession($org->stripe_checkout_session_id);
                if (($session['status'] ?? '') === 'complete') {
                    $this->syncSession($org,$session);
                    throw new RuntimeException('Abonnementet ble nettopp opprettet. Last siden på nytt og gi gratismåneden til neste betaling.');
                }
                if (($session['status'] ?? '') === 'open') $this->request('post','/checkout/sessions/'.$session['id'].'/expire',[],'expire-'.$session['id']);
                $org->update(['stripe_checkout_key'=>null,'stripe_checkout_session_id'=>null]);
            }
            if ($org->stripe_free_month_key !== $key) $org->update([
                'stripe_free_month_count'=>$months,'stripe_free_month_key'=>$key,'stripe_free_month_granted_at'=>now(),'stripe_free_month_applied_at'=>null,
            ]);
            if ($subscription) {
                $coupon=$this->coupon($org,$this->validatePrice());
                $updated=$this->request('post','/subscriptions/'.$subscription['id'],[
                    'discounts'=>[['coupon'=>$coupon]],'proration_behavior'=>'none',
                    'metadata'=>['free_month_key'=>$key],
                ],'dekkpilot-free-apply-'.$key);
                $this->applySubscription($org,$updated);
            }
            $this->queueNotices($org);
        });
    }

    public function activateFreeAccess(Organization $org, string $key): void
    {
        $this->locked($org,function(Organization $org) use($key) {
            if ($org->suspended_at) throw new RuntimeException('Virksomheten er stengt. Kontakt systemeier.');
            if ($org->stripe_free_month_key !== $key) throw new RuntimeException('Gratistildelingen er endret. Last siden på nytt.');
            if ($org->free_access_grant_key === $key) return;
            if (!$org->hasUnusedFreeGrant()) throw new RuntimeException('Ingen ubrukte gratismåneder er tildelt.');
            if ($org->stripe_customer_id) {
                $subscriptions=$this->request('get','/subscriptions',['customer'=>$org->stripe_customer_id,'status'=>'all','limit'=>100]);
                if ($subscriptions['has_more']??false) throw new RuntimeException('Abonnementet må avklares før gratisperioden kan startes.');
                foreach($subscriptions['data']??[] as $subscription) {
                    if (!in_array($subscription['status'],['canceled','incomplete_expired'],true)) throw new RuntimeException('Virksomheten har allerede et Stripe-abonnement. Gratismåneder må brukes på dette abonnementet.');
                }
            }
            if ($org->stripe_subscription_id && !in_array($org->subscription_status,['canceled','incomplete_expired'],true)) throw new RuntimeException('Virksomheten har allerede et Stripe-abonnement.');
            if ($org->stripe_checkout_key && !$org->stripe_checkout_session_id) throw new RuntimeException('Et påbegynt Stripe-forsøk må avklares først. Gjenoppta betalingsforsøket eller kontakt systemeier.');
            if ($org->stripe_checkout_session_id) {
                $session=$this->retrieveSession($org->stripe_checkout_session_id);
                if (($session['status']??'')==='complete') {
                    $this->syncSession($org,$session);
                    throw new RuntimeException('Stripe-abonnementet er allerede opprettet. Oppdater status.');
                }
                if (($session['status']??'')==='open') $session=$this->request('post','/checkout/sessions/'.$session['id'].'/expire',[],'expire-'.$session['id']);
                if (($session['status']??'')!=='expired') throw new RuntimeException('Stripe-forsøket er ikke avklart. Prøv igjen senere.');
            }
            $start=$org->hasFreeAccess()?$org->free_access_until->copy():now();
            $org->update(['billing_model'=>'stripe','free_access_started_at'=>$org->free_access_started_at??now(),
                'free_access_until'=>$start->timezone('Europe/Oslo')->addMonthsNoOverflow((int)$org->stripe_free_month_count)->utc(),
                'free_access_grant_key'=>$key,'stripe_checkout_key'=>null,'stripe_checkout_session_id'=>null]);
        });
    }

    private function queueNotices(Organization $org): void
    {
        $org->users()->where('active',true)->where('is_super_admin',false)->select('id')->chunkById(100,function($users) use ($org) {
            $rows=$users->map(fn($user)=>['organization_id'=>$org->id,'user_id'=>$user->id,'grant_key'=>$org->stripe_free_month_key,
                'months'=>$org->stripe_free_month_count,'granted_at'=>$org->stripe_free_month_granted_at,'seen_at'=>null])->all();
            if ($rows) \Illuminate\Support\Facades\DB::table('subscription_notices')->insertOrIgnore($rows);
        });
    }

    private function coupon(Organization $org, array $price): string
    {
        $id='dp-free-'.substr(hash('sha256',$org->public_id.':'.$org->stripe_free_month_key),0,32);
        // Deterministic IDs remain safe even beyond Stripe's idempotency-key retention.
        $existing=$this->request('get','/coupons/'.$id,[],null,null,true);
        if ($existing) return $existing['id'];
        return $this->request('post','/coupons',[
            'id'=>$id,'name'=>'DekkPilot – '.$org->stripe_free_month_count.' gratis mnd.','percent_off'=>100,'duration'=>$org->stripe_free_month_count>1?'repeating':'once',
            ...($org->stripe_free_month_count>1?['duration_in_months'=>(int)$org->stripe_free_month_count]:[]),
            'max_redemptions'=>1,'applies_to'=>['products'=>[$price['product']]],
            'metadata'=>['organization_id'=>(string)$org->id],
        ],'dekkpilot-coupon-'.$org->stripe_free_month_key)['id'];
    }

    private function request(string $method, string $path, array $data = [], ?string $key = null, ?array $settings = null, bool $allowMissing = false): array
    {
        $settings ??= $this->settings->get();
        if (blank($settings['secret'] ?? null)) throw new RuntimeException('Stripe-nøkkelen mangler.');
        $http=Http::withBasicAuth($settings['secret'],'')->acceptJson()->asForm()->connectTimeout(5)->timeout(20)
            ->withHeaders(['Stripe-Version'=>'2025-06-30.basil']);
        if ($key) $http=$http->withHeaders(['Idempotency-Key'=>$key]);
        try { $response=$http->$method('https://api.stripe.com/v1'.$path,$data); }
        catch (\Illuminate\Http\Client\ConnectionException) { throw new RuntimeException('Stripe svarte ikke. Prøv igjen; en eksisterende betaling blir ikke opprettet på nytt.'); }
        if ($allowMissing && $response->status()===404) return [];
        if (!$response->successful()) throw new RuntimeException('Stripe kunne ikke fullføre handlingen (HTTP '.$response->status().'). Kontroller oppsettet og hendelsesloggen i Stripe.');
        return $response->json();
    }
}
