<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Services\StripeBillingService;
use App\Services\StripeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StripeSubscriptionTest extends TestCase
{
    use RefreshDatabase;
    private array $subscription=[];
    private string $sessionStatus='open';
    private array $listed=[];
    private bool $stripeUnavailable=false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret'=>'sk_test_example','services.stripe.price_id'=>'price_month','services.stripe.webhook_secret'=>'whsec_example']);
        Http::preventStrayRequests();
    }

    private function user(string $role='owner', bool $super=false): User
    {
        $org=Organization::create(['public_id'=>(string)Str::uuid(),'name'=>'Dekk AS','email'=>'owner@example.no',
            'billing_model'=>'stripe','subscription_status'=>'incomplete']);
        $branch=Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'name'=>'Hoved','code'=>'HOVED']);
        return User::factory()->create(['organization_id'=>$org->id,'branch_id'=>$branch->id,'role'=>$role,'active'=>true,'is_super_admin'=>$super]);
    }

    private function gateway(Organization $org): void
    {
        $this->subscription=['id'=>'sub_current','customer'=>'cus_'.$org->id,'status'=>'active','collection_method'=>'charge_automatically',
            'metadata'=>['organization_id'=>(string)$org->id],'cancel_at_period_end'=>false,
            'items'=>['data'=>[['quantity'=>1,'current_period_end'=>now()->addMonth()->timestamp,'price'=>['id'=>'price_month']]]],
            'discounts'=>[]];
        Http::fake(function($r) use($org) {
            if ($this->stripeUnavailable) return Http::response([],503);
            $path=parse_url($r->url(),PHP_URL_PATH);
            if ($path==='/v1/prices/price_month') return Http::response(['id'=>'price_month','active'=>true,'currency'=>'nok','unit_amount'=>24900,'product'=>'prod_dekk','recurring'=>['interval'=>'month','interval_count'=>1],'tax_behavior'=>'inclusive']);
            if ($path==='/v1/customers') return Http::response(['id'=>'cus_'.$org->id]);
            if ($path==='/v1/subscriptions') return Http::response(['data'=>$this->listed,'has_more'=>false]);
            if ($path==='/v1/checkout/sessions' || $path==='/v1/checkout/sessions/cs_current') return Http::response([
                'id'=>'cs_current','url'=>'https://checkout.stripe.com/c/pay/test','mode'=>'subscription',
                'client_reference_id'=>(string)$org->id,'customer'=>'cus_'.$org->id,'status'=>$this->sessionStatus,
                'subscription'=>$this->sessionStatus==='complete'?'sub_current':null,
            ]);
            if ($path==='/v1/subscriptions/sub_current') {
                if ($r->method()==='POST') {
                    $this->subscription['metadata']=array_replace($this->subscription['metadata'],$r['metadata']??[]);
                    $this->subscription['discounts']=$r['discounts']??[];
                }
                return Http::response($this->subscription);
            }
            if (str_starts_with($path,'/v1/coupons/')) return Http::response([],404);
            if ($path==='/v1/coupons') return Http::response(['id'=>$r['id']]);
            if ($path==='/v1/billing_portal/sessions') return Http::response(['url'=>'https://billing.stripe.com/p/session/test']);
            if ($path==='/v1/billing_portal/configurations') return Http::response(['id'=>'bpc_current']);
            if ($path==='/v1/webhook_endpoints') return Http::response(['id'=>'we_current','secret'=>'whsec_created']);
            throw new \RuntimeException('Unexpected fake request: '.$path);
        });
    }

    private function link(User $user): Organization
    {
        $org=$user->organization;
        $org->update(['stripe_customer_id'=>'cus_'.$org->id,'stripe_subscription_id'=>'sub_current','subscription_status'=>'active','subscription_ends_at'=>now()->addMonth()]);
        $this->gateway($org);
        return $org;
    }

    private function webhook(string $id,string $type,array $object, ?string $secret=null)
    {
        $payload=json_encode(['id'=>$id,'type'=>$type,'data'=>['object'=>$object]]);
        $signature='t='.time().',v1='.hash_hmac('sha256',time().'.'.$payload,$secret??'whsec_example');
        return $this->call('POST','/webhooks/stripe',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_STRIPE_SIGNATURE'=>$signature],$payload);
    }

    public function test_unpaid_and_expired_stripe_accounts_are_blocked_but_billing_remains_accessible(): void
    {
        $user=$this->user('technician');
        $this->actingAs($user)->get('/')->assertRedirect('/abonnement');
        $this->getJson('/')->assertStatus(402);
        $this->get('/abonnement')->assertOk()->assertSee('Kontakt virksomhetens eier');
        $org=$this->link($user);
        $org->update(['subscription_ends_at'=>now()->subSecond()]);
        $this->get('/')->assertRedirect('/abonnement');
        $org->update(['subscription_ends_at'=>now()->addMonth(),'subscription_status'=>'past_due']);
        $this->get('/')->assertRedirect('/abonnement');
    }

    public function test_portal_return_checks_payment_and_only_restores_verified_active_access(): void
    {
        $owner=$this->user();$org=$this->link($owner);
        $org->update(['subscription_status'=>'past_due']);
        $this->subscription['status']='past_due';
        $this->actingAs($owner)->get('/abonnement')->assertOk()->assertSee('Oppdater betalingsmåte og betal');
        $this->getJson('/')->assertStatus(402);
        $this->post('/abonnement/portal')->assertRedirect('https://billing.stripe.com/p/session/test');
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/billing_portal/sessions') && $r['return_url']===route('billing.portal-return') && $r['customer']===$org->stripe_customer_id);
        $this->get(route('billing.portal-return'))->assertRedirect('/abonnement');
        $this->get('/')->assertRedirect('/abonnement');
        $this->subscription['status']='active';
        $this->get(route('billing.portal-return'))->assertRedirect('/abonnement');
        $this->assertTrue($org->fresh()->hasSubscriptionAccess());
        $this->get('/')->assertOk();
        $staff=$this->user('technician');
        $this->actingAs($staff)->get(route('billing.portal-return'))->assertForbidden();
    }

    public function test_invoice_accounts_with_overdue_payment_are_also_blocked_and_free_access_is_preserved(): void
    {
        $owner=$this->user();$org=$owner->organization;
        $org->update(['billing_model'=>'invoice','subscription_status'=>'past_due']);
        $this->actingAs($owner)->get('/')->assertRedirect('/abonnement');
        $token=Str::random(50);
        DB::table('personal_access_tokens')->insert(['user_id'=>$owner->id,'name'=>'Test','token_hash'=>hash('sha256',$token),'abilities'=>json_encode(['*']),'created_at'=>now()]);
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(402);
        $org->update(['free_access_until'=>now()->addDay()]);
        $this->assertTrue($org->fresh()->hasSubscriptionAccess());
        $org->update(['free_access_until'=>now()->subSecond()]);
        $this->assertFalse($org->fresh()->hasSubscriptionAccess());
    }

    public function test_checkout_is_reused_and_never_opens_access_before_payment(): void
    {
        $user=$this->user();$this->gateway($user->organization);
        $this->actingAs($user)->post('/abonnement/checkout',['accept_subscription'=>1])->assertRedirect('https://checkout.stripe.com/c/pay/test');
        $this->post('/abonnement/checkout',['accept_subscription'=>1])->assertRedirect('https://checkout.stripe.com/c/pay/test');
        Http::assertSentCount(7); // price/customer/list/create + price/list/retrieve
        $this->get('/')->assertRedirect('/abonnement');
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/checkout/sessions') && $r['customer']==='cus_'.$user->organization_id
            && $r['payment_method_collection']==='always' && $r->hasHeader('Idempotency-Key'));
    }

    public function test_success_verifies_session_ownership_and_current_subscription(): void
    {
        $user=$this->user();$org=$user->organization;$this->gateway($org);
        $this->actingAs($user)->post('/abonnement/checkout',['accept_subscription'=>1]);
        $this->sessionStatus='complete';$this->subscription['status']='incomplete';
        $this->get('/abonnement/ferdig?session_id=cs_current')->assertRedirect('/abonnement');
        $this->get('/')->assertRedirect('/abonnement');
        $this->subscription['status']='active';
        $this->post('/abonnement/oppdater')->assertSessionHasNoErrors();
        $this->get('/')->assertOk();
        $other=$this->user();
        $this->actingAs($other)->get('/abonnement/ferdig?session_id=cs_current')->assertSessionHasErrors('stripe');
        $this->assertFalse($other->organization->fresh()->hasSubscriptionAccess());
    }

    public function test_existing_remote_subscription_prevents_a_second_checkout(): void
    {
        $user=$this->user();$org=$this->link($user);$this->listed=[$this->subscription];
        $this->actingAs($user)->post('/abonnement/checkout',['accept_subscription'=>1])->assertSessionHasErrors('stripe');
        Http::assertNotSent(fn($r)=>$r->method()==='POST' && str_ends_with($r->url(),'/checkout/sessions'));
    }

    public function test_only_owner_admin_can_manage_payment_and_portal_uses_own_customer(): void
    {
        $staff=$this->user('manager');
        $this->actingAs($staff)->post('/abonnement/checkout',['accept_subscription'=>1])->assertForbidden();
        $this->post('/abonnement/portal')->assertForbidden();
        $owner=$this->user();$org=$this->link($owner);
        $this->actingAs($owner)->post('/abonnement/portal',['customer'=>'cus_other'])->assertRedirect('https://billing.stripe.com/p/session/test');
        Http::assertSent(fn($r)=>$r['customer']===$org->stripe_customer_id);
    }

    public function test_webhook_signature_duplicates_and_delayed_invoice_do_not_resurrect_canceled_access(): void
    {
        $user=$this->user();$org=$this->link($user);$this->subscription['status']='canceled';
        $event=['customer'=>$org->stripe_customer_id,'subscription'=>'sub_current','status'=>'paid'];
        $this->webhook('evt_bad','invoice.paid',$event,'wrong')->assertStatus(400);
        $this->webhook('evt_paid','invoice.paid',$event)->assertOk();
        $this->webhook('evt_paid','invoice.paid',$event)->assertOk();
        $this->assertSame('canceled',$org->fresh()->subscription_status);
        $this->assertDatabaseCount('billing_webhook_events',1);
        Http::assertSentCount(1);
    }

    public function test_webhook_ignores_old_subscription_and_retries_stripe_failure(): void
    {
        $user=$this->user();$org=$this->link($user);
        $this->webhook('evt_old','customer.subscription.deleted',['id'=>'sub_old','customer'=>$org->stripe_customer_id])->assertOk();
        Http::assertNothingSent();
        $this->stripeUnavailable=true;
        $this->webhook('evt_retry','invoice.payment_failed',['customer'=>$org->stripe_customer_id,'parent'=>['subscription_details'=>['subscription'=>'sub_current']]])->assertStatus(503);
        $this->assertDatabaseMissing('billing_webhook_events',['event_id'=>'evt_retry']);
    }

    public function test_checkout_webhook_requires_known_session_and_updates_access(): void
    {
        $user=$this->user();$org=$user->organization;$this->gateway($org);
        $this->actingAs($user)->post('/abonnement/checkout',['accept_subscription'=>1]);
        $this->sessionStatus='complete';
        $this->webhook('evt_checkout','checkout.session.completed',['id'=>'cs_current','customer'=>'cus_'.$org->id])->assertOk();
        $this->assertTrue($org->fresh()->hasSubscriptionAccess());
    }

    public function test_free_month_is_superadmin_only_and_discount_requires_card(): void
    {
        $owner=$this->user();$org=$owner->organization;$key=(string)Str::uuid();
        $this->actingAs($owner)->post(route('superadmin.stripe.free-month',$org),['grant_key'=>$key,'confirm'=>1])->assertForbidden();
        $super=$this->user('owner',true);
        $this->actingAs($super)->post(route('superadmin.stripe.free-month',$org),['grant_key'=>$key,'confirm'=>1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subscription_notices',1);
        $this->assertFalse($org->fresh()->hasSubscriptionAccess());
        $this->gateway($org);
        $this->actingAs($owner)->post('/abonnement/checkout',['accept_subscription'=>1])->assertSessionHasNoErrors();
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/coupons') && $r['percent_off']===100 && $r['duration']==='once' && $r['max_redemptions']===1 && $r['applies_to']['products']===['prod_dekk']);
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/checkout/sessions') && isset($r['discounts'][0]['coupon']) && $r['payment_method_collection']==='always');
    }

    public function test_later_free_months_are_allowed_but_retries_and_overlapping_discounts_are_not_duplicated(): void
    {
        $owner=$this->user();$org=$this->link($owner);$super=$this->user('owner',true);$key=(string)Str::uuid();
        $this->actingAs($super)->post(route('superadmin.stripe.free-month',$org),['grant_key'=>$key,'confirm'=>1])->assertSessionHasNoErrors();
        $this->assertNotNull($org->fresh()->stripe_free_month_applied_at);
        $this->post(route('superadmin.stripe.free-month',$org),['grant_key'=>$key,'confirm'=>1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subscription_notices',1);
        $this->post(route('superadmin.stripe.free-month',$org),['grant_key'=>(string)Str::uuid(),'confirm'=>1])->assertSessionHasErrors('stripe');
        $this->subscription['discounts']=[]; // Stripe has consumed the previous one-month discount.
        $this->flushSession();
        $this->post(route('superadmin.stripe.free-month',$org),['grant_key'=>(string)Str::uuid(),'confirm'=>1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subscription_notices',2);
    }

    public function test_notice_is_shown_only_after_login_once_per_grant_and_per_user(): void
    {
        $owner=$this->user();$org=$owner->organization;$super=$this->user('owner',true);
        $this->actingAs($super)->post(route('superadmin.stripe.free-month',$org),['grant_key'=>(string)Str::uuid(),'confirm'=>1]);
        $this->actingAs($owner)->get('/abonnement')->assertDontSee('Du har fått én gratis måned');
        $this->withSession(['billing_notice_login'=>true])->get('/')->assertRedirect('/abonnement');
        $this->assertDatabaseHas('subscription_notices',['user_id'=>$owner->id,'seen_at'=>null]);
        $this->get('/abonnement')->assertOk()->assertSee('Du har fått én gratis måned');
        $this->get('/abonnement')->assertDontSee('Du har fått én gratis måned');
        $this->withSession(['billing_notice_login'=>true])->get('/abonnement')->assertDontSee('Du har fått én gratis måned');
        DB::table('subscription_notices')->insert(['organization_id'=>$org->id,'user_id'=>$owner->id,'grant_key'=>(string)Str::uuid(),'granted_at'=>now()]);
        $this->withSession(['billing_notice_login'=>true])->get('/abonnement')->assertSee('Du har fått én gratis måned');
    }

    public function test_actual_login_waits_for_two_factor_before_consuming_free_month_notice(): void
    {
        $owner=$this->user();
        $owner->update(['password'=>'SterktPassord123','two_factor_secret'=>'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at'=>now(),'two_factor_recovery_codes'=>[\Illuminate\Support\Facades\Hash::make('ABCDE-FGHIJ')]]);
        DB::table('subscription_notices')->insert(['organization_id'=>$owner->organization_id,'user_id'=>$owner->id,
            'grant_key'=>(string)Str::uuid(),'granted_at'=>now()]);
        $this->post('/login',['email'=>$owner->email,'password'=>'SterktPassord123'])->assertRedirect('/tofaktor');
        $this->get('/tofaktor')->assertOk()->assertDontSee('Du har fått én gratis måned');
        $this->assertDatabaseHas('subscription_notices',['user_id'=>$owner->id,'seen_at'=>null]);
        $this->post('/tofaktor',['code'=>'ABCDE-FGHIJ'])->assertRedirect('/');
        $this->get('/')->assertRedirect('/abonnement');
        $this->get('/abonnement')->assertSee('Du har fått én gratis måned');
        $this->assertDatabaseMissing('subscription_notices',['user_id'=>$owner->id,'seen_at'=>null]);
        $this->get('/abonnement')->assertDontSee('Du har fått én gratis måned');
    }

    public function test_grant_notices_are_independent_for_each_employee(): void
    {
        $owner=$this->user();
        $staff=User::factory()->create(['organization_id'=>$owner->organization_id,'branch_id'=>$owner->branch_id,'role'=>'technician','active'=>true]);
        app(StripeBillingService::class)->grantFreeMonth($owner->organization,(string)Str::uuid());
        $this->assertDatabaseCount('subscription_notices',2);
        $this->actingAs($owner)->withSession(['billing_notice_login'=>true])->get('/abonnement')->assertSee('Du har fått én gratis måned');
        $this->actingAs($staff)->withSession(['billing_notice_login'=>true])->get('/abonnement')->assertSee('Du har fått én gratis måned');
        $this->assertSame(0,DB::table('subscription_notices')->whereNull('seen_at')->count());
    }

    public function test_canceled_customer_can_start_a_new_checkout_after_old_completed_session(): void
    {
        $owner=$this->user();$org=$this->link($owner);
        $org->update(['stripe_checkout_session_id'=>'cs_current','stripe_checkout_key'=>(string)Str::uuid()]);
        $this->sessionStatus='complete';$this->subscription['status']='canceled';
        $this->actingAs($owner)->post('/abonnement/checkout',['accept_subscription'=>1])->assertSessionHasNoErrors()->assertRedirect('https://checkout.stripe.com/c/pay/test');
        Http::assertSent(fn($r)=>$r->method()==='POST' && str_ends_with($r->url(),'/checkout/sessions'));
    }

    public function test_superadmin_setup_encrypts_secrets_and_activates_all_real_organizations(): void
    {
        config(['services.stripe.secret'=>null,'services.stripe.price_id'=>null,'services.stripe.webhook_secret'=>null]);
        $owner=$this->user();$owner->organization->update(['billing_model'=>'invoice','subscription_status'=>'active']);
        $demo=$this->user();$demo->organization->update(['organization_number'=>'DEMO-DEKKPILOT','billing_model'=>'invoice','subscription_status'=>'active']);
        $super=$this->user('owner',true);$this->gateway($owner->organization);
        $this->actingAs($owner)->get('/superadmin/stripe')->assertForbidden();
        $this->actingAs($super)->put('/superadmin/stripe',['secret'=>'sk_test_example','price_id'=>'price_month','activate_all'=>1])->assertSessionHasNoErrors();
        $this->assertSame('stripe',$owner->organization->fresh()->billing_model);
        $this->assertSame('incomplete',$owner->organization->fresh()->subscription_status);
        $this->assertSame('invoice',$demo->organization->fresh()->billing_model);
        $this->assertStringNotContainsString('sk_test_example',DB::table('platform_settings')->where('key','stripe.billing')->value('encrypted_value'));
        $this->assertSame('whsec_created',app(StripeSettings::class)->get()['webhook_secret']);
        $this->get('/superadmin/stripe')->assertOk()->assertDontSee('sk_test_example')->assertDontSee('whsec_created');
    }

    public function test_failed_setup_does_not_activate_accounts_or_flash_secrets(): void
    {
        $owner=$this->user();$owner->organization->update(['billing_model'=>'invoice']);$super=$this->user('owner',true);
        Http::fake(['api.stripe.com/*'=>Http::response([],401)]);
        $this->actingAs($super)->put('/superadmin/stripe',['secret'=>'sk_test_invalid','price_id'=>'price_month','activate_all'=>1])->assertSessionHasErrors('stripe')->assertSessionMissing('_old_input.secret');
        $this->assertSame('invoice',$owner->organization->fresh()->billing_model);
    }

    public function test_stripe_subscriptions_are_not_invoiced_again_by_monthly_statement(): void
    {
        $owner=$this->user();$owner->organization->update(['subscription_status'=>'active']);
        $this->artisan('billing:prepare',['--month'=>'2026-09'])->assertSuccessful();
        $this->assertDatabaseHas('billing_statements',['organization_id'=>$owner->organization_id,'subscription_cents'=>0,'total_cents'=>0]);
    }

    public function test_multiple_free_months_are_applied_once_and_notice_shows_count(): void
    {
        $owner=$this->user();$org=$owner->organization;$super=$this->user('owner',true);$this->gateway($org);
        $org->update(['stripe_customer_id'=>'cus_'.$org->id,'stripe_subscription_id'=>'sub_current']);
        $key=(string)Str::uuid();
        $this->actingAs($super)->post(route('superadmin.stripe.free-month',$org),['grant_key'=>$key,'months'=>3,'confirm'=>1])->assertSessionHasNoErrors();
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/coupons') && $r['duration']==='repeating' && $r['duration_in_months']===3);
        $this->post(route('superadmin.stripe.free-month',$org),['grant_key'=>$key,'months'=>3,'confirm'=>1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subscription_notices',1);
        $this->post(route('superadmin.stripe.free-month',$org),['grant_key'=>$key,'months'=>2,'confirm'=>1])->assertSessionHasErrors('stripe');
        $this->post(route('superadmin.stripe.free-month',$org),['grant_key'=>(string)Str::uuid(),'months'=>13,'confirm'=>1])->assertSessionHasErrors('months');
        $this->actingAs($owner)->withSession(['billing_notice_login'=>true])->get('/abonnement')->assertSee('3 gratis måneder');
        $this->get('/abonnement')->assertDontSee('Du har fått 3 gratis måneder');
    }
    public function test_invoice_snapshot_is_read_from_stripe_and_does_not_reopen_suspended_tenant(): void
    {
        $owner=$this->user();$org=$owner->organization;$this->gateway($org);
        $org->update(['stripe_customer_id'=>'cus_'.$org->id,'stripe_subscription_id'=>'sub_current','suspended_at'=>now()]);
        $this->subscription['latest_invoice']=['id'=>'in_current','customer'=>'cus_'.$org->id,'status'=>'paid','amount_paid'=>24900,'amount_remaining'=>0,'currency'=>'nok','secret'=>'not-stored'];
        app(StripeBillingService::class)->refresh($org);
        $this->assertSame(24900,$org->fresh()->stripe_latest_invoice['amount_paid']);
        $this->assertArrayNotHasKey('secret',$org->fresh()->stripe_latest_invoice);
        $this->assertFalse($org->fresh()->hasSubscriptionAccess());
        $this->assertNotNull($org->fresh()->stripe_synced_at);
        $this->actingAs($owner)->post(route('billing.checkout'),['accept_subscription'=>1])->assertSessionHasErrors('stripe');
    }

    public function test_unpaid_account_has_blocking_dialog_and_stripe_redirect_permission(): void
    {
        $owner=$this->user();$this->actingAs($owner);
        $page=$this->get('/abonnement')->assertOk()->assertSee('role="dialog"',false)->assertSee('Aktiver abonnementet')->assertDontSee('Ny kunde')->assertDontSee('Ny booking')->assertSee('Logg ut');
        $this->assertStringContainsString("form-action 'self' https://checkout.stripe.com https://billing.stripe.com",$page->headers->get('Content-Security-Policy'));
        $this->get('/kunder')->assertRedirect('/abonnement');
        $this->post('/kunder',['name'=>'Skal ikke opprettes'])->assertRedirect('/abonnement');
        $this->assertDatabaseCount('customers',0);
        $org=$owner->organization;$this->gateway($org);
        $response=$this->post(route('billing.checkout'),['accept_subscription'=>1])->assertRedirect('https://checkout.stripe.com/c/pay/test');
        $this->assertStringContainsString('https://checkout.stripe.com',$response->headers->get('Content-Security-Policy'));
        $this->get('/kunder')->assertRedirect('/abonnement');
    }
    public function test_paid_account_has_normal_billing_page_and_unrelated_pages_keep_strict_csp(): void
    {
        $owner=$this->user();$owner->organization->update(['stripe_subscription_id'=>'sub_active','subscription_status'=>'active','subscription_ends_at'=>now()->addMonth()]);
        $this->actingAs($owner)->get('/abonnement')->assertOk()->assertDontSee('role="dialog"',false)->assertSee('Åpne DekkPilot');
        $page=$this->get('/kunder')->assertOk();
        $this->assertStringNotContainsString('https://checkout.stripe.com',$page->headers->get('Content-Security-Policy'));
    }

    public function test_free_access_without_stripe_starts_once_and_expires_without_cron(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-04 12:00:00','UTC'));
        $owner=$this->user();$org=$owner->organization;$key=(string)Str::uuid();
        app(StripeBillingService::class)->grantFreeMonth($org,$key);
        $this->actingAs($owner)->withSession(['billing_notice_login'=>true])->get('/abonnement')->assertSee('Du har fått én gratis måned')->assertSee('Start gratisperioden uten Stripe');
        $this->post(route('billing.free-access'),['grant_key'=>$key,'confirm'=>1])->assertRedirect('/');
        $until=$org->fresh()->free_access_until->toIso8601String();
        $this->assertSame('2026-11-04 14:00',$org->fresh()->free_access_until->timezone('Europe/Oslo')->format('Y-m-d H:i'));
        $this->get('/')->assertOk()->assertSee('Gratis tilgang til');
        $this->post(route('billing.free-access'),['grant_key'=>$key,'confirm'=>1])->assertRedirect('/');
        $this->assertSame($until,$org->fresh()->free_access_until->toIso8601String());
        Http::assertNothingSent();
        $this->travelTo($org->fresh()->free_access_until->copy()->subDays(3));
        $this->get('/')->assertOk()->assertSee('Gratisperioden utløper snart');
        $this->travelTo($org->fresh()->free_access_until);
        $this->get('/kunder')->assertRedirect('/abonnement');
        $this->getJson('/kunder')->assertStatus(402);
        $this->get('/abonnement')->assertSee('Aktiver abonnementet')->assertDontSee('Start gratisperioden uten Stripe');
        $this->post(route('billing.free-access'),['grant_key'=>$key,'confirm'=>1]);
        $this->assertFalse($org->fresh()->hasSubscriptionAccess());
    }
    public function test_free_access_requires_actual_grant_owner_and_confirmation(): void
    {
        $owner=$this->user();$key=(string)Str::uuid();$this->actingAs($owner);
        $this->post(route('billing.free-access'),['grant_key'=>$key,'confirm'=>1])->assertSessionHasErrors('stripe');
        app(StripeBillingService::class)->grantFreeMonth($owner->organization,$key);
        $this->post(route('billing.free-access'),['grant_key'=>$key])->assertSessionHasErrors('confirm');
        $staff=$this->user('technician');
        $this->actingAs($staff)->post(route('billing.free-access'),['grant_key'=>$key,'confirm'=>1])->assertForbidden();
        $owner->organization->update(['suspended_at'=>now()]);
        $this->actingAs($owner)->post(route('billing.free-access'),['grant_key'=>$key,'confirm'=>1])->assertSessionHasErrors('stripe');
        $this->assertNull($owner->organization->fresh()->free_access_until);
    }
    public function test_consumed_free_access_is_not_also_discounted_in_stripe_and_new_grants_are_possible(): void
    {
        $owner=$this->user();$org=$owner->organization;$key=(string)Str::uuid();$service=app(StripeBillingService::class);
        $service->grantFreeMonth($org,$key);$service->activateFreeAccess($org,$key);
        $this->actingAs($owner)->post(route('billing.checkout'),['accept_subscription'=>1])->assertSessionHasErrors('stripe');
        $end=$org->fresh()->free_access_until;
        $this->travelTo($end->copy()->addSecond());
        $this->gateway($org);
        $service->checkout($org);
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/checkout/sessions') && !isset($r['discounts']));
        $org->refresh()->update(['stripe_checkout_key'=>null,'stripe_checkout_session_id'=>null,'stripe_customer_id'=>null]);
        $later=(string)Str::uuid();$service->grantFreeMonth($org,$later,2);$service->activateFreeAccess($org,$later);
        $this->assertTrue($org->fresh()->hasFreeAccess());
        $this->assertDatabaseCount('subscription_notices',2);
    }
    public function test_existing_stripe_session_is_expired_before_free_access_and_failure_keeps_access_closed(): void
    {
        $owner=$this->user();$org=$owner->organization;$key=(string)Str::uuid();$service=app(StripeBillingService::class);
        $service->grantFreeMonth($org,$key);$org->refresh()->update(['stripe_customer_id'=>'cus_pending','stripe_checkout_key'=>Str::uuid(),'stripe_checkout_session_id'=>'cs_pending']);
        Http::fake(['*/subscriptions*'=>Http::response(['data'=>[]]),'*/checkout/sessions/cs_pending'=>Http::response(['id'=>'cs_pending','status'=>'open']),'*/checkout/sessions/cs_pending/expire'=>Http::sequence()->push([],503)->push(['id'=>'cs_pending','status'=>'expired'])]);
        $this->actingAs($owner)->post(route('billing.free-access'),['grant_key'=>$key,'confirm'=>1])->assertSessionHasErrors('stripe');
        $this->assertFalse($org->fresh()->hasFreeAccess());
        $this->get('/abonnement');
        $this->post(route('billing.free-access'),['grant_key'=>$key,'confirm'=>1])->assertSessionHasNoErrors();
        $this->assertTrue($org->fresh()->hasFreeAccess());
        $this->assertNull($org->fresh()->stripe_checkout_session_id);
    }
}
