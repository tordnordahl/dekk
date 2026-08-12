<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CheckoutPayment;
use App\Models\IntegrationSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountingExportTest extends TestCase
{
    use RefreshDatabase;

    private function setupBooking(): array
    {
        $org=Organization::create(['public_id'=>Str::uuid(),'name'=>'Dekk AS']);
        $branch=Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'name'=>'Oslo','code'=>'OSL']);
        $user=User::factory()->create(['organization_id'=>$org->id,'branch_id'=>$branch->id,'role'=>'owner','active'=>true]);
        $customer=Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'branch_id'=>$branch->id,'customer_number'=>'K000001','name'=>'Ola Kunde','email'=>'ola@example.no']);
        $booking=Booking::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'branch_id'=>$branch->id,'customer_id'=>$customer->id,'reference'=>'B-TEST','service_name'=>'Sesongskift','agreed_price_cents'=>69900,'starts_at'=>now(),'ends_at'=>now()->addMinutes(40)]);
        return compact('org','user','booking');
    }

    public function test_completion_creates_exactly_one_invoice_basis(): void
    {
        ['user'=>$user,'booking'=>$booking]=$this->setupBooking();
        $this->actingAs($user)->post(route('bookings.complete',$booking))->assertRedirect();
        $this->actingAs($user)->post(route('bookings.complete',$booking))->assertRedirect();
        $this->assertDatabaseCount('invoice_exports',1);
        $this->assertDatabaseHas('invoice_exports',['booking_id'=>$booking->id,'status'=>'ready','total_cents'=>69900,'vat_cents'=>13980]);
    }

    public function test_accounting_key_is_encrypted_but_completion_waits_for_payment_choice(): void
    {
        ['org'=>$org,'user'=>$user,'booking'=>$booking]=$this->setupBooking();
        $secret='fiken-secret-token';
        $this->actingAs($user)->put(route('admin.accounting.save'),['provider'=>'fiken','api_key'=>$secret,'company_identifier'=>'dekk-as','auto_export'=>1,'payment_days'=>14,'income_account'=>'3000'])->assertRedirect();
        $setting=IntegrationSetting::where('provider','accounting_fiken')->firstOrFail();
        $this->assertStringNotContainsString($secret,$setting->encrypted_credentials);
        $this->assertSame($secret,json_decode(Crypt::decryptString($setting->encrypted_credentials),true)['api_key']);
        $this->actingAs($user)->post(route('bookings.complete',$booking))->assertRedirect();
        $this->assertDatabaseHas('invoice_exports',['booking_id'=>$booking->id,'status'=>'ready','provider'=>null]);
        $this->assertDatabaseHas('checkout_payments',['booking_id'=>$booking->id,'status'=>'pending','amount_cents'=>69900]);
    }

    public function test_fiken_export_uses_draft_lines_in_ore_and_does_not_send_invoice(): void
    {
        ['user'=>$user,'booking'=>$booking]=$this->setupBooking();
        $this->actingAs($user)->post(route('bookings.complete',$booking));
        $invoice=\App\Models\InvoiceExport::where('booking_id',$booking->id)->firstOrFail();$invoice->update(['provider'=>'fiken','request_key'=>(string)Str::uuid()]);
        Http::fake(function ($request) {
            if(str_ends_with($request->url(),'/invoices/drafts'))return Http::response('',201,['Location'=>'https://api.fiken.no/api/v2/companies/dekk-as/invoices/drafts/draft-123']);
            if(str_contains($request->url(),'/contacts')&&$request->method()==='POST')return Http::response('',201,['Location'=>'https://api.fiken.no/api/v2/companies/dekk-as/contacts/77']);
            return Http::response([],200);
        });
        $id=app(\App\Services\Accounting\FikenExporter::class)->export($invoice,['api_key'=>'secret','company_slug'=>'dekk-as','payment_days'=>14,'income_account'=>'3000']);
        $this->assertSame('draft-123',$id);
        Http::assertSent(fn($request)=>str_ends_with($request->url(),'/invoices/drafts')&&$request['lines'][0]['unitPrice']===55920&&$request['lines'][0]['vatType']==='HIGH'&&$request['orderReference']==='F-B-TEST');
        Http::assertNotSent(fn($request)=>str_ends_with($request->url(),'/invoices/send'));
    }

    public function test_tripletex_retry_reuses_saved_order_id(): void
    {
        ['user'=>$user,'booking'=>$booking]=$this->setupBooking();$this->actingAs($user)->post(route('bookings.complete',$booking));
        $invoice=\App\Models\InvoiceExport::where('booking_id',$booking->id)->firstOrFail();$invoice->update(['provider'=>'tripletex','request_key'=>(string)Str::uuid(),'external_order_id'=>'456']);
        Http::fake([
            'tripletex.no/v2/token/session/:createFromRefreshToken'=>Http::response(['value'=>['token'=>'session']],200),
            'tripletex.no/v2/order/456/:invoice*'=>Http::response(['value'=>['id'=>789]],200),
        ]);
        $id=app(\App\Services\Accounting\TripletexExporter::class)->export($invoice,['api_key'=>'jwt','company_id'=>'0']);
        $this->assertSame('789',$id);
        Http::assertNotSent(fn($request)=>$request->method()==='POST'&&str_ends_with($request->url(),'/order'));
    }

    public function test_another_tenant_cannot_queue_invoice(): void
    {
        ['user'=>$owner,'booking'=>$booking]=$this->setupBooking();
        $this->actingAs($owner)->post(route('bookings.complete',$booking));
        ['user'=>$other]=$this->setupBooking();
        $invoice=\App\Models\InvoiceExport::where('booking_id',$booking->id)->firstOrFail();
        $this->actingAs($other)->post(route('admin.accounting.queue',$invoice))->assertNotFound();
    }

    public function test_completed_booking_can_be_reopened_and_completed_again_without_duplicate_invoice(): void
    {
        ['user'=>$user,'booking'=>$booking]=$this->setupBooking();
        $this->actingAs($user)->post(route('bookings.complete',$booking))->assertRedirect();
        $this->actingAs($user)->post(route('bookings.reopen',$booking))->assertRedirect()->assertSessionHas('success','Jobben er satt tilbake til «Venter».');
        $this->assertDatabaseHas('bookings',['id'=>$booking->id,'status'=>'scheduled']);
        $this->assertDatabaseHas('invoice_exports',['booking_id'=>$booking->id,'status'=>'cancelled']);

        $this->actingAs($user)->post(route('bookings.complete',$booking))->assertRedirect();
        $this->assertDatabaseCount('invoice_exports',1);
        $this->assertDatabaseHas('invoice_exports',['booking_id'=>$booking->id,'status'=>'ready']);
    }

    public function test_reopening_exported_booking_warns_that_accounting_must_be_corrected(): void
    {
        ['user'=>$user,'booking'=>$booking]=$this->setupBooking();
        $this->actingAs($user)->post(route('bookings.complete',$booking));
        $invoice=\App\Models\InvoiceExport::where('booking_id',$booking->id)->firstOrFail();
        $invoice->update(['status'=>'exported','external_id'=>'FIKEN-1']);

        $this->actingAs($user)->post(route('bookings.reopen',$booking))->assertRedirect()->assertSessionHas('warning');
        $this->assertDatabaseHas('bookings',['id'=>$booking->id,'status'=>'scheduled']);
        $this->assertDatabaseHas('invoice_exports',['id'=>$invoice->id,'status'=>'exported']);
    }

    public function test_paid_receipt_can_be_printed_and_resent_from_an_old_booking(): void
    {
        ['user'=>$user,'booking'=>$booking]=$this->setupBooking();
        $this->actingAs($user)->post(route('bookings.complete',$booking))->assertRedirect();
        $payment=CheckoutPayment::where('booking_id',$booking->id)->firstOrFail();
        $payment->update(['status'=>'paid','payment_method'=>'cash','provider_reference'=>'KONTANT-TEST','paid_at'=>now()]);

        $this->actingAs($user)->get(route('bookings.receipt',$payment))->assertOk()
            ->assertSee('ELEKTRONISK SALGSKVITTERING')->assertSee('Org.nr.')
            ->assertSee('KONTANT-TEST')->assertSee('Kontant')->assertSee('MVA 25 %');
        $this->actingAs($user)->post(route('bookings.receipt.resend',$payment))->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('outbound_messages',['booking_id'=>$booking->id,'channel'=>'email','recipient'=>'ola@example.no','status'=>'queued']);

        ['user'=>$other]=$this->setupBooking();
        $this->actingAs($other)->get(route('bookings.receipt',$payment))->assertNotFound();
    }

    public function test_terminal_can_only_be_completed_by_api_and_attempt_times_out_after_sixty_seconds(): void
    {
        ['user'=>$user,'booking'=>$booking]=$this->setupBooking();
        $this->actingAs($user)->post(route('bookings.complete',$booking));
        $payment=CheckoutPayment::where('booking_id',$booking->id)->firstOrFail();
        $plain='terminal-test-token';$payment->update(['lookup_token_hash'=>hash('sha256',$plain)]);

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('checkout.complete'));
        Carbon::setTestNow(now());
        $this->actingAs($user)->post(route('checkout.start',[$payment,$plain]),['payment_method'=>'terminal','receipt_channel'=>'print'])->assertRedirect();
        $payment->refresh();$this->assertSame('processing',$payment->status);
        Carbon::setTestNow(now()->addSeconds(61));
        $this->getJson(route('checkout.status',[$payment,$plain]))->assertOk()->assertJsonPath('status','failed')->assertJsonPath('provider_status','TIMED_OUT');
        Carbon::setTestNow();
    }

    public function test_unimicro_connection_is_encrypted_tested_and_exports_invoice(): void
    {
        ['user'=>$user,'booking'=>$booking]=$this->setupBooking();
        $token='uni-oauth-token';$companyKey=(string)Str::uuid();
        $this->actingAs($user)->put(route('admin.accounting.save'),['provider'=>'unimicro','api_key'=>$token,'company_identifier'=>$companyKey,'api_base_url'=>'https://test.unimicro.no','environment'=>'test','payment_days'=>14,'income_account'=>'3000','distribution_plan_id'=>15,'payment_info_type_id'=>5])->assertRedirect()->assertSessionHasNoErrors();
        $setting=IntegrationSetting::where('provider','accounting_unimicro')->firstOrFail();
        $this->assertStringNotContainsString($token,$setting->encrypted_credentials);
        Http::fake([
            'test.unimicro.no/api/biz/companysettings'=>Http::response(['CompanyName'=>'Uni Test AS'],200),
            'test.unimicro.no/api/biz/invoices'=>Http::response(['ID'=>987],201),
        ]);
        $this->actingAs($user)->post(route('admin.accounting.test'))->assertRedirect()->assertSessionHas('success');
        $this->actingAs($user)->post(route('bookings.complete',$booking));
        $invoice=\App\Models\InvoiceExport::where('booking_id',$booking->id)->firstOrFail();
        $id=app(\App\Services\Accounting\UniMicroExporter::class)->export($invoice,json_decode(Crypt::decryptString($setting->encrypted_credentials),true));
        $this->assertSame('987',$id);
        Http::assertSent(fn($request)=>str_ends_with($request->url(),'/api/biz/invoices')&&$request->hasHeader('CompanyKey',$companyKey)&&$request['DistributionPlanID']===15&&$request['Items'][0]['VatPercent']===25.0);
    }
}
