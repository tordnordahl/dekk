<?php
namespace Tests\Feature;

use App\Models\{Organization,Branch,User,Customer,Vehicle,Booking,CheckoutPayment,IntegrationSetting};
use App\Services\{MerchantPaymentSettings,MerchantStripeService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantPaymentsTest extends TestCase
{
    use RefreshDatabase;
    private array $session=[];
    private bool $unavailable=false;
    private string $token='customer-payment-token';

    private function fixture(): array
    {
        $org=Organization::create(['public_id'=>Str::uuid(),'name'=>'Verksted AS']);
        $branch=Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'name'=>'Hoved','code'=>'H']);
        $user=User::factory()->create(['organization_id'=>$org->id,'branch_id'=>$branch->id,'role'=>'owner','active'=>true]);
        $customer=Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'branch_id'=>$branch->id,'customer_number'=>'K1','name'=>'Kunde']);
        $vehicle=Vehicle::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'customer_id'=>$customer->id,'registration_number'=>'AB12345']);
        $booking=Booking::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'branch_id'=>$branch->id,'customer_id'=>$customer->id,'vehicle_id'=>$vehicle->id,
            'reference'=>'B-'.Str::random(6),'service_name'=>'Sesongskift','agreed_price_cents'=>69900,'starts_at'=>now(),'ends_at'=>now()->addMinutes(40)]);
        $this->actingAs($user)->post(route('bookings.complete',$booking))->assertSessionHasNoErrors();
        $payment=CheckoutPayment::where('booking_id',$booking->id)->firstOrFail();
        $this->token=Str::random(64);
        $payment->update(['lookup_token_hash'=>hash('sha256',$this->token)]);
        return compact('org','user','payment');
    }

    private function gateway(CheckoutPayment $payment): void
    {
        Http::preventStrayRequests();
        $this->session=['id'=>'cs_merchant','mode'=>'payment','status'=>'open','payment_status'=>'unpaid','livemode'=>true,
            'client_reference_id'=>$payment->public_id,'amount_total'=>69900,'currency'=>'nok','url'=>'https://checkout.stripe.com/c/pay/merchant',
            'metadata'=>['organization_id'=>(string)$payment->organization_id,'payment_id'=>$payment->public_id]];
        Http::fake(function($r) {
            if ($this->unavailable) return Http::response([],503);
            $path=parse_url($r->url(),PHP_URL_PATH);
            if ($path==='/v1/account') return Http::response(['id'=>'acct_workshop','charges_enabled'=>true,'business_profile'=>['name'=>'Verksted AS']]);
            if ($path==='/v1/webhook_endpoints') return Http::response(['id'=>'we_merchant','secret'=>'whsec_merchant']);
            if ($path==='/v1/checkout/sessions/cs_merchant/expire') { $this->session['status']='expired';return Http::response($this->session); }
            if (str_starts_with($path,'/v1/checkout/sessions')) return Http::response($this->session);
            throw new \RuntimeException('Unexpected request '.$path);
        });
    }

    private function configure(array $f): void
    {
        $this->gateway($f['payment']);
        $this->actingAs($f['user'])->put(route('admin.payments.stripe'),['merchant_secret'=>'rk_live_workshop','active'=>1])->assertSessionHasNoErrors();
    }

    private function start(CheckoutPayment $payment,string $method='stripe')
    {
        return $this->post(route('checkout.start',[$payment,$this->token]),['payment_method'=>$method,'receipt_channel'=>'print']);
    }

    private function webhook(Organization $org, string $secret='whsec_merchant')
    {
        $body=json_encode(['type'=>'checkout.session.completed','data'=>['object'=>['id'=>'cs_merchant']]]);
        $signature='t='.time().',v1='.hash_hmac('sha256',time().'.'.$body,$secret);
        return $this->call('POST',route('merchant.stripe.webhook',$org),[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_STRIPE_SIGNATURE'=>$signature],$body);
    }

    public function test_clear_payment_setup_page_and_tenant_stripe_keys_are_separate_from_subscription(): void
    {
        $f=$this->fixture();$this->configure($f);
        $this->get(route('admin.payments'))->assertOk()->assertSee('DekkPilot-abonnementet')->assertSee('Zettle')->assertSee('API keys')->assertDontSee('rk_live_workshop')->assertDontSee('whsec_merchant');
        $row=IntegrationSetting::where('organization_id',$f['org']->id)->where('provider','payment_stripe')->firstOrFail();
        $this->assertStringNotContainsString('rk_live_workshop',$row->encrypted_credentials);
        $this->assertDatabaseMissing('platform_settings',['key'=>'stripe.billing']);
        $this->start($f['payment'])->assertRedirect('https://checkout.stripe.com/c/pay/merchant');
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/checkout/sessions') && $r['mode']==='payment' && $r['line_items'][0]['price_data']['unit_amount']===69900 && $r->hasHeader('Authorization','Basic '.base64_encode('rk_live_workshop:')));
    }

    public function test_other_tenant_and_technician_cannot_configure_or_confirm_payments(): void
    {
        $f=$this->fixture();$other=$this->fixture();
        $this->actingAs($other['user'])->post(route('payments.zettle.confirm',$f['payment']),['confirm'=>1])->assertNotFound();
        $other['user']->update(['role'=>'technician']);
        $this->actingAs($other['user'])->get(route('admin.payments'))->assertForbidden();
        $this->put(route('admin.payments.stripe'),['merchant_secret'=>'rk_live_other','active'=>1])->assertForbidden();
    }

    public function test_disabled_provider_test_key_and_failed_setup_cannot_accept_live_payment(): void
    {
        $f=$this->fixture();$this->gateway($f['payment']);
        $this->start($f['payment'])->assertSessionHasErrors('payment');
        $this->flushSession();
        $this->put(route('admin.payments.stripe'),['merchant_secret'=>'sk_test_example','active'=>1])->assertSessionHasErrors('payment');
        $this->assertFalse(app(MerchantPaymentSettings::class)->get($f['org']->id,'stripe')['active']);
        $this->put(route('admin.payments.stripe'),['merchant_secret'=>'sk_test_example','active'=>0])->assertSessionHasNoErrors();
        $this->assertFalse(app(MerchantPaymentSettings::class)->get($f['org']->id,'stripe')['active']);
    }

    public function test_repeated_start_reuses_session_and_cannot_switch_to_cash_during_stripe(): void
    {
        $f=$this->fixture();$this->configure($f);
        $this->start($f['payment'])->assertRedirect('https://checkout.stripe.com/c/pay/merchant');
        $this->start($f['payment'])->assertRedirect('https://checkout.stripe.com/c/pay/merchant');
        $creates=Http::recorded(fn($r)=>$r->method()==='POST' && str_ends_with($r->url(),'/checkout/sessions'));
        $this->assertCount(1,$creates);
        $this->start($f['payment'],'cash')->assertSessionHasErrors('payment');
        $this->assertSame('processing',$f['payment']->fresh()->status);
        $this->get(route('checkout.payment',[$f['payment'],$this->token]))->assertOk()->assertSee('Fortsett hos Stripe');
    }

    public function test_return_url_does_not_mark_an_unpaid_session_paid(): void
    {
        $f=$this->fixture();$this->configure($f);$this->start($f['payment']);
        $this->get(route('checkout.stripe.return',[$f['payment'],$this->token]))->assertRedirect(route('checkout.payment',[$f['payment'],$this->token]));
        $this->assertNull($f['payment']->fresh()->paid_at);
    }

    public function test_signed_webhook_verifies_payment_and_duplicate_does_not_repeat_completion(): void
    {
        $f=$this->fixture();$this->configure($f);$this->start($f['payment']);
        $this->session['status']='complete';$this->session['payment_status']='paid';$this->session['payment_intent']='pi_merchant';
        $this->webhook($f['org'],'wrong')->assertStatus(400);
        $this->webhook($f['org'])->assertOk();$paidAt=$f['payment']->fresh()->paid_at;
        $this->webhook($f['org'])->assertOk();
        $this->assertSame('paid',$f['payment']->fresh()->status);
        $this->assertTrue($paidAt->equalTo($f['payment']->fresh()->paid_at));
        $this->assertSame('cancelled',$f['payment']->invoiceExport->fresh()->status);
        $this->assertSame('stripe',$f['payment']->fresh()->payment_method);
    }

    public function test_wrong_amount_or_test_mode_webhooks_never_mark_real_job_paid(): void
    {
        $f=$this->fixture();$this->configure($f);$this->start($f['payment']);
        $this->session['status']='complete';$this->session['payment_status']='paid';$this->session['payment_intent']='pi_merchant';
        $this->session['amount_total']=1;$this->webhook($f['org'])->assertStatus(503);
        $this->session['amount_total']=69900;$this->session['livemode']=false;$this->webhook($f['org'])->assertStatus(503);
        $this->assertNull($f['payment']->fresh()->paid_at);
    }

    public function test_cancel_expires_remote_session_before_unlocking_other_methods(): void
    {
        $f=$this->fixture();$this->configure($f);$this->start($f['payment']);
        $this->post(route('checkout.stripe.cancel',[$f['payment'],$this->token]))->assertSessionHasNoErrors();
        $this->assertSame('expired',$this->session['status']);
        $this->assertNull($f['payment']->fresh()->stripe_checkout_key);
        $this->start($f['payment'],'cash')->assertSessionHasNoErrors();
        $this->assertSame('paid',$f['payment']->fresh()->status);
    }

    public function test_nightly_invoicing_checks_stripe_before_sending_an_invoice(): void
    {
        $f=$this->fixture();$this->configure($f);$this->start($f['payment']);
        $this->session['status']='complete';$this->session['payment_status']='paid';$this->session['payment_intent']='pi_merchant';
        $f['payment']->update(['expires_at'=>now()->subMinute()]);
        $this->artisan('checkout:invoice-expired')->assertSuccessful();
        $this->assertSame('paid',$f['payment']->fresh()->status);
        $this->assertNull($f['payment']->fresh()->invoiced_at);
    }

    public function test_stripe_outage_leaves_payment_pending_and_recoverable_not_invoiced(): void
    {
        $f=$this->fixture();$this->configure($f);$this->start($f['payment']);
        $this->unavailable=true;$f['payment']->update(['expires_at'=>now()->subMinute()]);
        $this->artisan('checkout:invoice-expired')->assertSuccessful();
        $this->assertSame('processing',$f['payment']->fresh()->status);
        $this->assertNull($f['payment']->fresh()->invoiced_at);
    }

    public function test_zettle_requires_staff_reference_amount_and_explicit_confirmation(): void
    {
        $f=$this->fixture();
        $this->put(route('admin.payments.zettle'),['name'=>'Zettle i kassen','active'=>1,'manual_confirmation'=>1])->assertSessionHasNoErrors();
        $this->start($f['payment'],'zettle')->assertSessionHasNoErrors();
        $this->get(route('checkout.payment',[$f['payment'],$this->token]))->assertOk()->assertSee('Kvitterings- eller transaksjonsreferanse');
        $this->post(route('payments.zettle.confirm',$f['payment']),['provider_reference'=>'Z-123','confirmed_amount'=>'1.00','confirm'=>1])->assertSessionHasErrors('payment');
        $this->flushSession();
        $this->post(route('payments.zettle.confirm',$f['payment']),['provider_reference'=>'Z-123','confirmed_amount'=>'699.00','confirm'=>1])->assertSessionHasNoErrors();
        $this->assertSame('paid',$f['payment']->fresh()->status);
        $this->assertSame('Z-123',$f['payment']->fresh()->provider_reference);
        $this->assertDatabaseHas('audit_logs',['action'=>'merchant.zettle.payment_confirmed','user_id'=>$f['user']->id]);
    }

    public function test_customer_cannot_confirm_zettle_and_staff_can_resolve_a_declined_payment(): void
    {
        $f=$this->fixture();
        app(MerchantPaymentSettings::class)->save($f['org']->id,'zettle',['name'=>'Zettle'],true,$f['user']->id);
        $this->start($f['payment'],'zettle');
        auth()->logout();
        $this->post(route('payments.zettle.confirm',$f['payment']),['provider_reference'=>'FAKE','confirmed_amount'=>'699','confirm'=>1])->assertRedirect('/login');
        $this->actingAs($f['user'])->post(route('payments.zettle.cancel',$f['payment']),['confirm_not_paid'=>1])->assertSessionHasNoErrors();
        $this->assertSame('pending',$f['payment']->fresh()->status);
    }
}
