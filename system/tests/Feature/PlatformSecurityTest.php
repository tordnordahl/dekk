<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OutboundMessage;
use App\Models\Booking;
use App\Models\ServiceProduct;
use App\Models\WorkBay;
use App\Models\User;
use App\Models\Vehicle;
use App\Mail\QuoteMail;
use App\Models\TireProduct;
use App\Models\TireSet;
use Illuminate\Support\Facades\Mail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $org = Organization::create(['public_id' => Str::uuid(), 'name' => 'Test AS']);
        $branch = Branch::create(['public_id' => Str::uuid(), 'organization_id' => $org->id, 'name' => 'Oslo', 'code' => 'OSL']);
        return User::factory()->create(['organization_id' => $org->id, 'branch_id' => $branch->id, 'active' => true]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_authenticated_user_can_open_dashboard(): void
    {
        $this->actingAs($this->user())->get('/')->assertOk()->assertSee('DekkPilot');
    }

    public function test_api_rejects_missing_and_accepts_hashed_token(): void
    {
        $user = $this->user();
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        $plain = Str::random(64);
        DB::table('personal_access_tokens')->insert(['user_id' => $user->id, 'name' => 'test', 'token_hash' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($plain)->getJson('/api/v1/customers')->assertOk();
    }

    public function test_customer_lists_are_tenant_isolated(): void
    {
        $user = $this->user();
        Customer::create(['public_id' => Str::uuid(), 'organization_id' => $user->organization_id, 'branch_id' => $user->branch_id, 'customer_number' => 'K000001', 'name' => 'Synlig kunde']);
        $other = Organization::create(['public_id' => Str::uuid(), 'name' => 'Annen AS']);
        Customer::create(['public_id' => Str::uuid(), 'organization_id' => $other->id, 'customer_number' => 'K000001', 'name' => 'Skjult kunde']);
        $this->actingAs($user)->get('/kunder')->assertSee('Synlig kunde')->assertDontSee('Skjult kunde');
    }

    public function test_inventory_can_search_by_customer_name_and_registration_number(): void
    {
        $user = $this->user();
        $customer = Customer::create(['public_id' => Str::uuid(), 'organization_id' => $user->organization_id, 'branch_id' => $user->branch_id, 'customer_number' => 'K000002', 'name' => 'Kari Nordmann']);
        Vehicle::create(['public_id' => Str::uuid(), 'organization_id' => $user->organization_id, 'customer_id' => $customer->id, 'registration_number' => 'AB12345']);

        $this->actingAs($user)->get('/lager?q=Kari')->assertOk()->assertSee('AB12345');
        $this->actingAs($user)->get('/lager?q=AB123')->assertOk()->assertSee('Kari Nordmann');
    }

    public function test_tire_labels_and_physical_status_flow_are_tenant_isolated(): void
    {
        $user = $this->user();
        $customer = Customer::create(['public_id' => Str::uuid(), 'organization_id' => $user->organization_id, 'branch_id' => $user->branch_id, 'customer_number' => 'K000099', 'name' => 'Etikett Kunde']);
        $vehicle = Vehicle::create(['public_id' => Str::uuid(), 'organization_id' => $user->organization_id, 'customer_id' => $customer->id, 'registration_number' => 'EL12345']);
        $set = TireSet::create(['public_id' => Str::uuid(), 'organization_id' => $user->organization_id, 'vehicle_id' => $vehicle->id, 'code' => 'HJ-TEST123', 'season' => 'winter', 'kind' => 'complete_wheels', 'quantity' => 4, 'status' => 'received']);

        $labelResponse = $this->actingAs($user)->get('/lager/etiketter?ids='.$set->id);
        $labelResponse->assertOk()->assertSee('EL12345')->assertSee('HJ-TEST123')->assertHeader('X-Frame-Options','SAMEORIGIN');
        $labelPolicy = (string) $labelResponse->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("object-src 'none'", $labelPolicy);
        $this->assertStringContainsString("frame-ancestors 'self'", $labelPolicy);
        $this->actingAs($user)->patch(route('tire-sets.status', $set), ['status' => 'workshop'])->assertRedirect();
        $this->assertDatabaseHas('tire_sets', ['id' => $set->id, 'status' => 'workshop']);

        $otherUser = $this->user();
        $this->actingAs($otherUser)->get('/lager/etiketter?ids='.$set->id)->assertNotFound();
        $this->actingAs($otherUser)->patch(route('tire-sets.status', $set), ['status' => 'delivered'])->assertNotFound();
    }

    public function test_vehicle_lookup_explains_when_api_key_is_missing(): void
    {
        config(['services.vegvesen.api_key' => null]);
        $user = $this->user();
        $customer = Customer::create(['public_id' => Str::uuid(), 'organization_id' => $user->organization_id, 'branch_id' => $user->branch_id, 'customer_number' => 'K000003', 'name' => 'Lookup Kunde']);

        $this->actingAs($user)->get(route('vehicles.lookup', $customer).'?registration_number=AB12345')
            ->assertSessionHasErrors('registration_number');
    }

    public function test_only_admin_can_generate_and_remove_dummy_data(): void
    {
        $employee = $this->user();
        $this->actingAs($employee)->get('/admin')->assertForbidden();

        $employee->update(['role' => 'owner']);
        config(['services.vegvesen.api_key'=>'test-key']);
        $vehicleResponse = ['kjoretoydataListe' => [[
            'forstegangsregistrering' => ['registrertForstegangNorgeDato' => '2021-03-01'],
            'godkjenning' => ['tekniskGodkjenning' => ['tekniskeData' => [
                'generelt' => ['merke' => [['merke' => 'Volvo']], 'handelsbetegnelse' => ['XC60'], 'understellsnummer' => 'TESTVIN123456789'],
                'akslinger' => ['akslingListe' => [['dekkOgFelg' => ['dekkdimensjon' => '235/55 R19', 'felgdimensjon' => '7.5Jx19']]]],
            ]]],
        ]]];
        Http::fake(fn($request) => Http::response($vehicleResponse, 200));
        $this->actingAs($employee)->post('/admin/dummydata', ['registration_numbers' => "AB12345\nCD67890"])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('vehicles', 2);
        $this->assertDatabaseCount('tire_sets', 2);
        $this->assertDatabaseHas('customers', ['notes' => '[DUMMY] Generert fra adminverktøyet.']);
        $this->assertDatabaseHas('vehicles',['registration_number'=>'AB12345','make'=>'Volvo','model'=>'XC60','recommended_tire_size'=>'235/55 R19']);
        $this->assertDatabaseHas('tire_sets',['size'=>'235/55 R19']);

        $this->actingAs($employee)->delete('/admin/dummydata', ['confirmation' => 'SLETT DUMMYDATA'])->assertRedirect();
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('tire_sets', 0);
    }

    public function test_quote_uses_hashed_expiring_token_and_can_be_accepted_once(): void
    {
        Mail::fake();
        $user = $this->user();
        $customer = Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'branch_id'=>$user->branch_id,'customer_number'=>'K000009','name'=>'Tilbud Kunde','email'=>'kunde@example.no']);
        $vehicle = Vehicle::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'customer_id'=>$customer->id,'registration_number'=>'EV12345']);
        $product = TireProduct::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'sku'=>'TEST-1','brand'=>'Nokian','model'=>'Test','size'=>'205/55 R16','season'=>'winter','price_cents'=>199900,'stock_quantity'=>8]);

        $this->actingAs($user)->post('/tilbud',['customer_id'=>$customer->id,'vehicle_id'=>$vehicle->id,'tire_product_id'=>$product->id,'quantity'=>4])->assertRedirect();
        Mail::assertSent(QuoteMail::class);
        $quote = \App\Models\Quote::first();
        $this->assertSame(64, strlen($quote->access_token_hash));

        $mail = Mail::sent(QuoteMail::class)->first();
        $token = basename(parse_url($mail->responseUrl, PHP_URL_PATH));
        $this->get('/tilbud/svar/'.$token)->assertOk()->assertSee('Godta valgt tilbud');
        $this->post('/tilbud/svar/'.$token,['decision'=>'accepted'])->assertSessionHasErrors('terms_accepted');
        $this->get('/tilbud/svar/'.$token.'/kjopsvilkar')->assertOk()->assertSee('Kjøpsvilkår');
        $this->post('/tilbud/svar/'.$token,['decision'=>'accepted','terms_accepted'=>1])->assertRedirect();
        $this->assertDatabaseHas('quotes',['id'=>$quote->id,'status'=>'accepted']);
        $this->assertNotNull($quote->fresh()->purchase_terms_accepted_at);
        $this->post('/tilbud/svar/'.$token,['decision'=>'declined'])->assertStatus(409);
    }

    public function test_admin_can_store_vegvesen_key_encrypted_without_exposing_it(): void
    {
        $user = $this->user();
        $user->update(['role' => 'owner']);
        $secret = 'svv-super-secret-api-key-123456789';
        $this->actingAs($user)->put('/admin/integrasjoner/vegvesen', ['api_key' => $secret])->assertRedirect();
        $stored = \App\Models\IntegrationSetting::firstOrFail();
        $this->assertStringNotContainsString($secret, $stored->encrypted_credentials);
        $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee($secret);
    }

    public function test_message_queue_respects_consent_and_booking_link_is_single_use(): void
    {
        $user=$this->user();$user->update(['role'=>'owner']);
        $customer=Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'branch_id'=>$user->branch_id,'customer_number'=>'K000020','name'=>'Samtykket Kunde','email'=>'ja@example.no','marketing_consent'=>true]);
        Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'branch_id'=>$user->branch_id,'customer_number'=>'K000021','name'=>'Reservert Kunde','email'=>'nei@example.no','marketing_consent'=>false]);
        $this->actingAs($user)->post('/admin/kommunikasjon/send',['audience'=>'all_consented','channel'=>'email','subject'=>'Test','body'=>'Hei'])->assertRedirect();
        $this->assertDatabaseCount('outbound_messages',1);$this->assertDatabaseHas('outbound_messages',['recipient'=>'ja@example.no']);
        $token=Str::random(64);$booking=\App\Models\Booking::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'branch_id'=>$user->branch_id,'customer_id'=>$customer->id,'reference'=>'B-TEST20','service_name'=>'Sesongskift','starts_at'=>now()->addDays(10),'ends_at'=>now()->addDays(10)->addMinutes(45),'confirmation_status'=>'pending','confirmation_token_hash'=>hash('sha256',$token)]);
        $this->get('/booking/bekreft/'.$token)->assertOk()->assertSee('Bekreft timen');
        $this->post('/booking/bekreft/'.$token,['decision'=>'confirmed'])->assertRedirect();
        $this->assertDatabaseHas('bookings',['id'=>$booking->id,'confirmation_status'=>'confirmed']);
        $this->post('/booking/bekreft/'.$token,['decision'=>'declined'])->assertStatus(409);
    }

    public function test_email_preview_uses_real_template_without_queuing_and_is_tenant_isolated(): void
    {
        $user = $this->user();
        $user->update(['role' => 'owner']);

        $this->actingAs($user)->post(route('admin.communications.preview'), [
            'subject' => 'Viktig beskjed',
            'body' => "Hei!\nDette er forhåndsvisningen.",
        ])->assertOk()->assertSee('Dette er forhåndsvisningen.')->assertSee('Denne meldingen ble sendt fra kundesystemet.');
        $this->assertDatabaseCount('outbound_messages', 0);

        $message = OutboundMessage::create([
            'public_id' => Str::uuid(),
            'organization_id' => $user->organization_id,
            'channel' => 'email',
            'recipient' => 'kunde@example.no',
            'subject' => 'Lagret e-post',
            'body' => 'Kun riktig virksomhet skal se dette.',
            'status' => 'queued',
            'scheduled_at' => now(),
        ]);
        $this->actingAs($user)->get(route('admin.communications.message-preview', $message))
            ->assertOk()->assertSee('Kun riktig virksomhet skal se dette.')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $otherUser = $this->user();
        $otherUser->update(['role' => 'owner']);
        $this->actingAs($otherUser)->get(route('admin.communications.message-preview', $message))->assertNotFound();
    }

    public function test_booking_can_be_overbooked_without_selecting_a_work_bay_and_is_marked(): void
    {
        $user=$this->user();$user->update(['role'=>'owner']);
        $customer=Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'branch_id'=>$user->branch_id,'customer_number'=>'K-CAP','name'=>'Kapasitetskunde']);
        $vehicle=Vehicle::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'customer_id'=>$customer->id,'registration_number'=>'CAP123']);
        $service=ServiceProduct::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'code'=>'SKIFT','name'=>'Dekkskift','category'=>'tire_change','fixed_price_cents'=>69900,'duration_minutes'=>45,'active'=>true]);
        foreach([1,2] as $number)WorkBay::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'branch_id'=>$user->branch_id,'name'=>'Bukk '.$number,'code'=>'B-'.$number,'type'=>'tire_lift','active'=>true]);
        $starts=now()->addDays(2)->setTime(10,0);$ends=$starts->copy()->addMinutes(45);
        foreach([1,2] as $number)Booking::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'branch_id'=>$user->branch_id,'customer_id'=>$customer->id,'reference'=>'B-CAP-'.$number,'service_name'=>'Dekkskift','starts_at'=>$starts,'ends_at'=>$ends]);

        $this->actingAs($user)->post(route('bookings.store'),['customer_id'=>$customer->id,'vehicle_ids'=>[$vehicle->id],'service_product_ids'=>[$service->id],'starts_at'=>$starts->format('Y-m-d H:i:s'),'duration'=>45])
            ->assertRedirect()->assertSessionHas('warning');
        $this->assertDatabaseCount('bookings',3);
        $this->assertDatabaseHas('bookings',['customer_id'=>$customer->id,'work_bay_id'=>null]);
        $this->actingAs($user)->get(route('bookings'))->assertOk()->assertSee('Overbooket');
    }

    public function test_booking_search_and_multi_vehicle_creation_are_tenant_scoped(): void
    {
        $user=$this->user();$user->update(['role'=>'owner']);
        $customer=Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'branch_id'=>$user->branch_id,'customer_number'=>'K-FLEET','name'=>'Nord Bilpark AS']);
        $first=Vehicle::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'customer_id'=>$customer->id,'registration_number'=>'AB12345','make'=>'Volvo','model'=>'XC60']);
        $second=Vehicle::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'customer_id'=>$customer->id,'registration_number'=>'CD67890','make'=>'Toyota','model'=>'Proace']);
        $other=$this->user();
        $hiddenCustomer=Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$other->organization_id,'branch_id'=>$other->branch_id,'customer_number'=>'K-HIDDEN','name'=>'Skjult Kunde']);
        Vehicle::create(['public_id'=>Str::uuid(),'organization_id'=>$other->organization_id,'customer_id'=>$hiddenCustomer->id,'registration_number'=>'ZZ99999']);

        $this->actingAs($user)->getJson(route('bookings.customer-search',['q'=>'Nord']))->assertOk()->assertJsonPath('data.0.name','Nord Bilpark AS')->assertJsonCount(2,'data.0.vehicles');
        $this->actingAs($user)->getJson(route('bookings.customer-search',['q'=>'AB123']))->assertOk()->assertJsonPath('data.0.id',$customer->id);
        $this->actingAs($user)->getJson(route('bookings.customer-search',['q'=>'ZZ999']))->assertOk()->assertJsonCount(0,'data');

        $service=ServiceProduct::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'code'=>'FLAATE','name'=>'Flåteskift','category'=>'tire_change','fixed_price_cents'=>79900,'duration_minutes'=>45,'active'=>true]);
        $extra=ServiceProduct::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'code'=>'VASK','name'=>'Hjulvask','category'=>'workshop','fixed_price_cents'=>19900,'duration_minutes'=>15,'active'=>true]);
        $this->actingAs($user)->post(route('bookings.store'),['customer_id'=>$customer->id,'vehicle_ids'=>[$first->id,$second->id],'service_product_ids'=>[$service->id,$extra->id],'starts_at'=>now()->addDay()->format('Y-m-d H:i:s'),'duration'=>60])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseCount('bookings',2);
        $this->assertDatabaseHas('bookings',['customer_id'=>$customer->id,'vehicle_id'=>$first->id]);
        $this->assertDatabaseHas('bookings',['customer_id'=>$customer->id,'vehicle_id'=>$second->id]);
        $this->assertDatabaseCount('booking_service_product',4);
        $this->assertDatabaseHas('bookings',['service_name'=>'Flåteskift + Hjulvask','agreed_price_cents'=>99800]);
    }

    public function test_dashboard_ranks_business_customers_by_documented_value(): void
    {
        $user=$this->user();
        $best=Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'branch_id'=>$user->branch_id,'type'=>'business','customer_number'=>'B001','name'=>'Beste Bedrift AS','organization_number'=>'999111222','email'=>'best@example.no']);
        $other=Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'branch_id'=>$user->branch_id,'type'=>'business','customer_number'=>'B002','name'=>'Annen Bedrift AS']);
        $vehicle=Vehicle::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'customer_id'=>$best->id,'registration_number'=>'PIPE01']);
        TireSet::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'vehicle_id'=>$vehicle->id,'code'=>'PIPE-SET','season'=>'winter','kind'=>'complete_wheels','size'=>'205/55 R16','quantity'=>4,'minimum_tread_depth'=>2.2,'status'=>'stored']);
        TireProduct::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'sku'=>'PIPE-PROD','brand'=>'Test','model'=>'Vinter','size'=>'205/55 R16','season'=>'winter','price_cents'=>200000,'stock_quantity'=>8,'active'=>true]);
        foreach([[$best,250000],[$best,150000],[$other,100000]] as $index=>[$customer,$value])Booking::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'branch_id'=>$user->branch_id,'customer_id'=>$customer->id,'reference'=>'RANK-'.$index,'service_name'=>'Dekkskift','agreed_price_cents'=>$value,'starts_at'=>now()->subDays(2),'ends_at'=>now()->subDays(2)->addMinutes(45),'status'=>'completed']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('Deres beste bedriftskunder')->assertSee('Åpne statistikk');
        $this->actingAs($user)->get(route('statistics'))->assertOk()->assertSee('Deres beste bedriftskunder')->assertSee('8 000 kr ikke sendt')->assertSee('1 mulige tilbud er ikke sendt')->assertSeeInOrder(['Beste Bedrift AS','4 000 kr','Annen Bedrift AS','1 000 kr']);
    }
}
