<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ServiceProduct;
use App\Models\ServiceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaasSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_verifies_brreg_accepts_terms_and_requires_stripe(): void
    {
        $this->fakeBrreg('999999999', 'Offisielt Dekkhotell AS');

        $this->post('/registrer', $this->registrationData())->assertRedirect('/abonnement');

        $organization = Organization::where('organization_number', '999999999')->firstOrFail();
        $this->assertSame('Offisielt Dekkhotell AS', $organization->name);
        $this->assertSame('incomplete', $organization->subscription_status);
        $this->assertSame('stripe', $organization->billing_model);
        $this->assertNotNull($organization->brreg_verified_at);
        $this->assertDatabaseHas('users', ['organization_id' => $organization->id, 'email' => 'eier@nytt.no', 'role' => 'owner']);
        $this->assertDatabaseCount('legal_acceptances', 3);
        $this->assertDatabaseCount('storage_locations', 5);
        $this->assertSame(8, ServiceProduct::where('organization_id',$organization->id)->where('active',true)->count());
        $this->assertDatabaseHas('service_products',['organization_id'=>$organization->id,'code'=>'SKIFT','name'=>'Sesongskift','duration_minutes'=>40]);
        $this->assertDatabaseHas('service_products',['organization_id'=>$organization->id,'code'=>'HOTELL','name'=>'Dekkhotell']);
        $this->assertTrue(ServiceSetting::where('organization_id',$organization->id)->whereNotNull('branch_id')->exists());
        $this->get('/kunder')->assertRedirect('/abonnement');
    }

    public function test_unknown_organization_is_rejected(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response([], 404)]);

        $this->post('/registrer', $this->registrationData())->assertSessionHasErrors('organization_number');
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_old_public_promo_code_does_not_bypass_payment(): void
    {
        $this->fakeBrreg('999999999', 'Rabatt AS');
        $this->post('/registrer', $this->registrationData() + ['promo_code'=>'GRATIS'.now()->format('my')])->assertRedirect('/abonnement');
        $this->assertSame('incomplete', Organization::firstOrFail()->subscription_status);
        $this->get('/kunder')->assertRedirect('/abonnement');
    }

    public function test_public_lookup_returns_verified_company(): void
    {
        $this->fakeBrreg('999999999', 'Oppslag AS');
        $this->getJson('/registrer/virksomhet?organization_number=999999999')
            ->assertOk()->assertJsonPath('name', 'Oppslag AS');
    }

    public function test_checkout_requires_authentication_and_webhook_requires_signature(): void
    {
        $this->post('/abonnement/checkout')->assertRedirect('/login');
        $this->postJson('/webhooks/stripe')->assertStatus(400);
    }

    private function registrationData(): array
    {
        return ['organization_number' => '999999999', 'name' => 'Ny Eier', 'email' => 'eier@nytt.no', 'password' => 'EtVeldigSterkt123', 'password_confirmation' => 'EtVeldigSterkt123', 'eula' => 1, 'privacy' => 1, 'price_terms' => 1];
    }

    private function fakeBrreg(string $number, string $name): void
    {
        Http::fake(["data.brreg.no/enhetsregisteret/api/enheter/{$number}" => Http::response(['organisasjonsnummer' => $number, 'navn' => $name, 'organisasjonsform' => ['kode' => 'AS', 'beskrivelse' => 'Aksjeselskap'], 'forretningsadresse' => ['adresse' => ['Testveien 1'], 'postnummer' => '0001', 'poststed' => 'OSLO'], 'registreringsdatoEnhetsregisteret' => '2020-01-01', 'erSlettet' => false])]);
    }
}
