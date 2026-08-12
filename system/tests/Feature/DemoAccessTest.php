<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_link_refreshes_calendar_and_blocks_writes(): void
    {
        $organization = Organization::create(['public_id' => (string) Str::uuid(), 'name' => '[DEMO] Test', 'organization_number' => 'DEMO-DEKKPILOT', 'subscription_status' => 'active']);
        $branch = Branch::create(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'name' => 'Hoved', 'code' => 'HOVED', 'active' => true]);
        $customer = Customer::create(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'branch_id' => $branch->id, 'customer_number' => 'DEMO001', 'name' => 'Demokunde']);
        Vehicle::create(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'customer_id' => $customer->id, 'registration_number' => 'EV12345']);

        $this->get('/demo/test/test')->assertRedirect('/')->assertSessionHas('demo_read_only', true);
        $this->get('/')->assertOk()->assertSee('Skrivebeskyttet demo');
        $this->assertGreaterThanOrEqual(35, $organization->bookings()->count());

        $this->post('/kunder', ['name' => 'Skal ikke lagres'])->assertSessionHasErrors('demo');
        $this->assertDatabaseMissing('customers', ['name' => 'Skal ikke lagres']);
    }

    public function test_test_credentials_open_the_same_read_only_demo(): void
    {
        $organization = Organization::create(['public_id' => (string) Str::uuid(), 'name' => '[DEMO] Test', 'organization_number' => 'DEMO-DEKKPILOT', 'subscription_status' => 'active']);
        $branch = Branch::create(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'name' => 'Hoved', 'code' => 'HOVED', 'active' => true]);
        $customer = Customer::create(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'branch_id' => $branch->id, 'customer_number' => 'DEMO001', 'name' => 'Demokunde']);
        Vehicle::create(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'customer_id' => $customer->id, 'registration_number' => 'EV12345']);

        $this->post('/login', ['email' => 'test', 'password' => 'test'])->assertRedirect('/')->assertSessionHas('demo_read_only', true);
    }
}
