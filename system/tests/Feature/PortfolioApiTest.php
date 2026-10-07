<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\TireSet;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PortfolioApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private string $keypair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = bin2hex(random_bytes(32));
        $this->keypair = sodium_crypto_box_keypair();
        config([
            'portfolio.enabled' => true,
            'portfolio.token_hash' => hash('sha256', $this->token),
            'portfolio.recipient_public_key' => base64_encode(sodium_crypto_box_publickey($this->keypair)),
        ]);
    }

    public function test_disabled_and_missing_or_wrong_credentials_fail_closed(): void
    {
        config(['portfolio.enabled' => false]);
        $this->getJson('/api/v1/portfolio/overview')->assertNotFound();
        config(['portfolio.enabled' => true]);
        $this->getJson('/api/v1/portfolio/overview')->assertUnauthorized();
        $this->withToken(str_repeat('a', 64))->getJson('/api/v1/portfolio/overview')->assertUnauthorized();
    }

    public function test_trial_dates_and_discount_months_are_exposed_without_guessing_stripe_trial_end(): void
    {
        $this->travelTo(now()->startOfDay());
        $end = now()->addMonthsNoOverflow(2);
        Organization::create([
            'public_id' => Str::uuid(), 'name' => 'Free',
            'free_access_started_at' => now(), 'free_access_until' => $end,
            'stripe_free_month_count' => 2,
        ]);
        Organization::create([
            'public_id' => Str::uuid(), 'name' => 'Discount', 'subscription_status' => 'active',
            'stripe_free_month_applied_at' => now(), 'stripe_free_month_count' => 1,
        ]);
        Organization::create([
            'public_id' => Str::uuid(), 'name' => 'Unknown trial', 'subscription_status' => 'trialing',
        ]);
        $response = $this->withToken($this->token)->getJson('/api/v1/portfolio/overview')->assertOk();
        $plain = sodium_crypto_box_seal_open(base64_decode($response->json('ciphertext')), $this->keypair);
        $rows = json_decode($plain, true)['customers'];
        $this->assertSame($end->toIso8601String(), $rows[0]['trial_ends_at']);
        $this->assertSame(2, $rows[0]['trial_months_granted']);
        $this->assertSame('trialing', $rows[1]['status']);
        $this->assertSame('discount_estimate', $rows[1]['trial_date_source']);
        $this->assertNull($rows[2]['trial_ends_at']);
    }

    public function test_response_is_encrypted_allowlisted_and_read_only(): void
    {
        Organization::create([
            'public_id' => Str::uuid(), 'name' => 'Syntetisk verksted',
            'email' => 'must-not-leak@example.test', 'subscription_status' => 'active',
        ]);
        $response = $this->withToken($this->token)->getJson('/api/v1/portfolio/overview');
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('Syntetisk verksted', $response->getContent());
        $plain = sodium_crypto_box_seal_open(base64_decode($response->json('ciphertext')), $this->keypair);
        $payload = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('Syntetisk verksted', $payload['customers'][0]['name']);
        $this->assertStringNotContainsString('must-not-leak', $plain);
        $this->assertArrayNotHasKey('stripe_subscription_id', $payload['customers'][0]);
        $this->assertFalse($payload['payment_confirmed']);
        $this->assertSame(24900, $payload['customers'][0]['monthly_price_minor']);
        $this->postJson('/api/v1/portfolio/overview')->assertStatus(405);
        $this->assertSame(1, Organization::count());
    }

    public function test_encryption_configuration_and_page_size_are_validated(): void
    {
        config(['portfolio.recipient_public_key' => 'broken']);
        $this->withToken($this->token)->getJson('/api/v1/portfolio/overview')->assertStatus(503);
        config(['portfolio.recipient_public_key' => base64_encode(sodium_crypto_box_publickey($this->keypair))]);
        $this->getJson('/api/v1/portfolio/overview?per_page=201')->assertUnprocessable();
    }

    public function test_production_rejects_http(): void
    {
        $this->app->instance('env', 'production');
        $this->withToken($this->token)->getJson('/api/v1/portfolio/overview')->assertForbidden();
        $this->getJson('https://localhost/api/v1/portfolio/overview')->assertOk();
    }

    public function test_free_and_suspended_customers_are_not_counted_as_paying(): void
    {
        foreach ([['Free', ['free_access_until' => now()->addDay()]], ['Closed', ['suspended_at' => now()]]] as [$name, $extra]) {
            Organization::create(array_merge([
                'public_id' => Str::uuid(), 'name' => $name,
                'email' => strtolower($name).'@example.test', 'subscription_status' => 'active',
            ], $extra));
        }
        $response = $this->withToken($this->token)->getJson('/api/v1/portfolio/overview')->assertOk();
        $plain = sodium_crypto_box_seal_open(base64_decode($response->json('ciphertext')), $this->keypair);
        $this->assertSame(['trialing', 'suspended'], array_column(json_decode($plain, true)['customers'], 'status'));
    }

    public function test_demo_organizations_and_their_data_are_excluded_from_all_counts(): void
    {
        $real = Organization::create(['public_id' => Str::uuid(), 'name' => 'Real', 'email' => 'real@example.test']);
        $demo = Organization::create(['public_id' => Str::uuid(), 'name' => 'Demo', 'email' => 'demo@example.test']);
        $demo->forceFill(['exclude_from_portfolio' => true])->save();
        Organization::create([
            'public_id' => Str::uuid(), 'name' => 'Built-in', 'organization_number' => 'DEMO-DEKKPILOT',
        ]);
        foreach ([$real, $demo] as $org) {
            $branch = Branch::create([
                'public_id' => Str::uuid(), 'organization_id' => $org->id, 'name' => 'Test', 'code' => 'T',
            ]);
            $customer = Customer::create([
                'public_id' => Str::uuid(), 'organization_id' => $org->id,
                'branch_id' => $branch->id, 'customer_number' => 'TEST', 'name' => 'Sensitive name',
            ]);
            $vehicle = Vehicle::create([
                'public_id' => Str::uuid(), 'organization_id' => $org->id,
                'customer_id' => $customer->id, 'registration_number' => 'TEST123',
            ]);
            TireSet::create([
                'public_id' => Str::uuid(), 'organization_id' => $org->id, 'vehicle_id' => $vehicle->id,
                'code' => 'S1', 'quantity' => 4, 'kind' => 'complete_wheels', 'status' => 'stored',
            ]);
        }
        $response = $this->withToken($this->token)->getJson('/api/v1/portfolio/overview')->assertOk();
        $plain = sodium_crypto_box_seal_open(base64_decode($response->json('ciphertext')), $this->keypair);
        $payload = json_decode($plain, true);
        $this->assertSame(['Real'], array_column($payload['customers'], 'name'));
        $this->assertSame(1, $payload['pagination']['total']);
        foreach (['organizations', 'end_customers', 'vehicles', 'sets', 'stored_sets', 'branches'] as $field) {
            $this->assertSame(1, $payload['statistics'][$field], $field);
        }
        $this->assertSame(4, $payload['statistics']['tire_units']);
        $this->assertSame(1, $payload['customers'][0]['statistics']['end_customers']);
        $this->assertStringNotContainsString('Sensitive name', $plain);
        $this->assertStringNotContainsString('TEST123', $plain);
    }
}
