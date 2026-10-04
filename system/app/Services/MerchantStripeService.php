<?php
namespace App\Services;

use App\Models\CheckoutPayment;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MerchantStripeService
{
    public function __construct(private readonly MerchantPaymentSettings $settings, private readonly CheckoutPaymentService $payments) {}

    public function configure(Organization $org, array $values, bool $active): array
    {
        $account=$this->request($values,'get','/account');
        if (!empty($values['account_id']) && $values['account_id']!==$account['id']) throw new RuntimeException('Nøkkelen tilhører en annen Stripe-konto. Behold kontoen som har mottatt virksomhetens betalinger.');
        $live=(bool)preg_match('/^(sk|rk)_live_/',$values['secret']);
        if ($active && (!$live || !($account['charges_enabled']??false))) throw new RuntimeException('Aktivering krever live-nøkkel og en Stripe-konto som er godkjent for betalinger.');
        $values['account_id']=$account['id'];
        $values['account_name']=$account['business_profile']['name']??$account['settings']['dashboard']['display_name']??$org->name;
        $values['live']=$live;
        if ($active && empty($values['webhook_secret'])) {
            $url=route('merchant.stripe.webhook',$org);
            if (!str_starts_with($url,'https://') && !app()->environment('testing')) throw new RuntimeException('Betalingsvarsler krever HTTPS.');
            $endpoint=$this->request($values,'post','/webhook_endpoints',[
                'url'=>$url,'api_version'=>'2025-06-30.basil',
                'enabled_events'=>['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired'],
            ],'dp-merchant-webhook-'.$org->public_id);
            $values['webhook_secret']=$endpoint['secret'];
            $values['webhook_id']=$endpoint['id'];
        }
        $values['verified_at']=now()->toIso8601String();
        return $values;
    }

    public function locked(CheckoutPayment $payment, callable $callback): mixed
    {
        return Cache::lock('checkout:payment:'.$payment->id,180)->block(5,fn()=>$callback($payment->fresh()));
    }

    // Called under the payment lock shared with other payment methods and daily invoicing.
    public function create(CheckoutPayment $payment, string $token): string
    {
        $config=$this->settings->get($payment->organization_id,'stripe');
        if (!($config['active']??false) || !($config['live']??false)) throw new RuntimeException('Verkstedet har ikke aktivert Stripe for kundebetalinger.');
        if ($payment->stripe_checkout_session_id) {
            $session=$this->session($payment,$config);
            $this->apply($payment,$session);
            if (($session['status']??'')==='open') return $session['url'];
            if ($payment->fresh()->status==='paid') return route('checkout.receipt',[$payment,$token]);
            throw new RuntimeException('Betalingsøkten er avsluttet. Avbryt forsøket før du velger ny betalingsmåte.');
        }
        if (!$payment->stripe_checkout_key) {
            if ($payment->expires_at->isPast() || $payment->amount_cents<1 || $payment->invoiced_at || $payment->invoiceExport->status==='exported') throw new RuntimeException('Betalingen er utløpt eller allerede fakturert. Kontakt verkstedet.');
            $data=[
                'mode'=>'payment','payment_method_types'=>['card'],'client_reference_id'=>$payment->public_id,
                'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>strtolower($payment->currency),'unit_amount'=>$payment->amount_cents,'product_data'=>['name'=>'Verkstedtjenester – '.$payment->booking->reference]]]],
                'metadata'=>['organization_id'=>(string)$payment->organization_id,'payment_id'=>$payment->public_id],
                'success_url'=>route('checkout.stripe.return',[$payment,$token]),
                'cancel_url'=>route('checkout.payment',[$payment,$token]),'expires_at'=>now()->addMinutes(31)->timestamp,
            ];
            $payment->update(['stripe_checkout_key'=>(string)Str::uuid(),'stripe_account_id'=>$config['account_id'],
                'provider'=>'stripe','payment_method'=>'stripe','status'=>'processing','provider_payload'=>['stripe_request'=>$data]]);
        }
        $session=$this->request($config,'post','/checkout/sessions',data_get($payment->provider_payload,'stripe_request'), 'dp-payment-'.$payment->stripe_checkout_key);
        $payment->update(['stripe_checkout_session_id'=>$session['id'],'provider_status'=>'WAITING_FOR_PAYMENT',
            'provider_payload'=>array_merge($payment->provider_payload??[],['stripe_url'=>$session['url']])]);
        return $session['url'];
    }

    public function synchronize(CheckoutPayment $payment): CheckoutPayment
    {
        if (!$payment->stripe_checkout_session_id) return $payment;
        $session=$this->session($payment,$this->settings->get($payment->organization_id,'stripe'));
        $this->apply($payment,$session);
        return $payment->fresh();
    }

    public function cancel(CheckoutPayment $payment): CheckoutPayment
    {
        if ($payment->status==='paid' || !$payment->stripe_checkout_key) return $payment;
        if ($payment->stripe_checkout_key && !$payment->stripe_checkout_session_id) throw new RuntimeException('Stripe-forsøket må gjenopptas før det kan avbrytes.');
        if ($payment->stripe_checkout_session_id) {
            $config=$this->settings->get($payment->organization_id,'stripe');
            $session=$this->session($payment,$config);
            if (($session['status']??'')==='open') {
                try { $session=$this->request($config,'post','/checkout/sessions/'.$session['id'].'/expire',[],'expire-'.$session['id']); }
                catch (RuntimeException) { $session=$this->session($payment,$config); }
            }
            $this->apply($payment,$session);
            if ($payment->fresh()->status==='paid') return $payment->fresh();
            if (($session['status']??'')!=='expired') throw new RuntimeException('Stripe behandler betalingen. Vent på bekreftelse før annen betaling eller fakturering.');
        }
        $payment->update(['stripe_checkout_key'=>null,'stripe_checkout_session_id'=>null,'stripe_account_id'=>null,
            'status'=>'pending','provider'=>'none','payment_method'=>null,'provider_payload'=>null,'provider_status'=>null]);
        return $payment->fresh();
    }

    private function session(CheckoutPayment $payment,array $config): array
    {
        if (($config['account_id']??null)!==$payment->stripe_account_id) throw new RuntimeException('Stripe-kontoen samsvarer ikke med betalingsforsøket.');
        return $this->request($config,'get','/checkout/sessions/'.rawurlencode($payment->stripe_checkout_session_id));
    }

    private function apply(CheckoutPayment $payment,array $session): void
    {
        if (($session['id']??null)!==$payment->stripe_checkout_session_id || ($session['mode']??'')!=='payment'
            || ($session['client_reference_id']??'')!==$payment->public_id
            || (string)data_get($session,'metadata.organization_id')!==(string)$payment->organization_id
            || data_get($session,'metadata.payment_id')!==$payment->public_id
            || ($session['amount_total']??null)!==(int)$payment->amount_cents
            || strtolower($session['currency']??'')!==strtolower($payment->currency) || ($session['livemode']??false)!==true) {
            throw new RuntimeException('Stripe-bekreftelsen samsvarer ikke med virksomhet, beløp eller betalingsforsøk.');
        }
        if (($session['status']??'')==='complete' && ($session['payment_status']??'')==='paid' && !empty($session['payment_intent'])) {
            $this->payments->complete($payment,'stripe',$session['payment_intent'],'PAID');
        }
    }

    private function request(array $config,string $method,string $path,array $data=[],?string $key=null): array
    {
        if (empty($config['secret'])) throw new RuntimeException('Virksomhetens Stripe-nøkkel mangler.');
        $http=Http::withBasicAuth($config['secret'],'')->asForm()->acceptJson()->connectTimeout(5)->timeout(20)->withHeaders(['Stripe-Version'=>'2025-06-30.basil']);
        if ($key) $http=$http->withHeaders(['Idempotency-Key'=>$key]);
        try { $response=$http->$method('https://api.stripe.com/v1'.$path,$data); }
        catch (\Illuminate\Http\Client\ConnectionException) { throw new RuntimeException('Stripe svarte ikke. Gjenoppta samme betalingsforsøk; ikke ta betalt på nytt før status er avklart.'); }
        if (!$response->successful()) throw new RuntimeException('Stripe avviste handlingen (HTTP '.$response->status().'). Kontroller virksomhetens nøkkel og rettigheter.');
        return $response->json();
    }
}
