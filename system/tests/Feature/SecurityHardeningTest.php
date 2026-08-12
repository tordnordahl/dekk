<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\TireSet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_stored_html_is_escaped_and_booking_javascript_avoids_html_injection(): void
    {
        [$organization, $branch, $user] = $this->tenant();
        $customer = Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$organization->id,'branch_id'=>$branch->id,'customer_number'=>'XSS-1','name'=>'<img src=x onerror=alert(1)>']);
        Vehicle::create(['public_id'=>Str::uuid(),'organization_id'=>$organization->id,'customer_id'=>$customer->id,'registration_number'=>'XX12345','make'=>'</option><script>alert(1)</script>','model'=>'Test']);

        $response = $this->actingAs($user)->get('/bookinger');

        $response->assertOk()->assertDontSee('<img src=x onerror=alert(1)>', false)->assertDontSee('</option><script>alert(1)</script>', false);
        $script = file_get_contents(public_path('booking-picker.js'));
        $this->assertStringNotContainsString('innerHTML', $script);
        $this->assertStringContainsString('textContent', $script);
    }

    public function test_security_headers_block_active_content_and_sensitive_caching(): void
    {
        $response = $this->get('/login');
        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Cache-Control', 'no-store, private');
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("script-src 'self'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
    }

    public function test_demo_accounts_cannot_create_api_tokens(): void
    {
        [$organization, $branch] = $this->tenant('DEMO-DEKKPILOT');
        User::factory()->create(['organization_id'=>$organization->id,'branch_id'=>$branch->id,'email'=>'demo-api@example.no','password'=>'VerySecure123','active'=>true]);

        $this->postJson('/api/v1/tokens',['email'=>'demo-api@example.no','password'=>'VerySecure123','device_name'=>'Test'])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_customer_service_api_token_cannot_change_wheel_status(): void
    {
        [$organization, $branch, $user] = $this->tenant();
        $user->update(['role'=>'customer_service']);
        $customer=Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$organization->id,'branch_id'=>$branch->id,'customer_number'=>'K1','name'=>'Kunde']);
        $vehicle=Vehicle::create(['public_id'=>Str::uuid(),'organization_id'=>$organization->id,'customer_id'=>$customer->id,'registration_number'=>'AB12345']);
        $set=TireSet::create(['public_id'=>Str::uuid(),'organization_id'=>$organization->id,'vehicle_id'=>$vehicle->id,'code'=>'SEC-1','season'=>'winter','kind'=>'complete_wheels','quantity'=>4,'status'=>'stored']);
        $plain=Str::random(64);
        DB::table('personal_access_tokens')->insert(['user_id'=>$user->id,'name'=>'Test','token_hash'=>hash('sha256',$plain),'abilities'=>'["*"]','expires_at'=>now()->addHour(),'created_at'=>now(),'updated_at'=>now()]);

        $this->withToken($plain)->patchJson('/api/v1/tire-sets/'.$set->id.'/status',['status'=>'delivered'])->assertForbidden();
        $this->assertSame('stored',$set->fresh()->status);
    }

    private function tenant(?string $organizationNumber = null): array
    {
        $organization=Organization::create(['public_id'=>Str::uuid(),'name'=>'Sikker test','organization_number'=>$organizationNumber,'subscription_status'=>'active']);
        $branch=Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$organization->id,'name'=>'Hoved','code'=>'HOVED','active'=>true]);
        $user=User::factory()->create(['organization_id'=>$organization->id,'branch_id'=>$branch->id,'role'=>'owner','active'=>true]);
        return[$organization,$branch,$user];
    }
}
