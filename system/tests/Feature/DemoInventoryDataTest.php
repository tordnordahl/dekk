<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\StorageLocation;
use App\Models\TireSet;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoInventoryDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_scaled_demo_has_real_car_names_fleets_and_multiple_wheel_sets(): void
    {
        $org=Organization::create(['public_id'=>Str::uuid(),'name'=>'Demo','organization_number'=>'DEMO-DEKKPILOT']);
        $branch=Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'name'=>'Hoved','code'=>'HOVED']);
        foreach(['A-01','B-01'] as $code)StorageLocation::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'branch_id'=>$branch->id,'code'=>$code,'capacity'=>100,'active'=>true]);

        $this->artisan('demo:scale-inventory',['--sets'=>24])->assertSuccessful();

        $this->assertDatabaseHas('vehicles',['make'=>'Toyota','model'=>'RAV4']);
        $this->assertDatabaseMissing('vehicles',['make'=>'Nokian']);
        $business=Customer::where('organization_id',$org->id)->where('type','business')->withCount('vehicles')->orderByDesc('vehicles_count')->firstOrFail();
        $this->assertGreaterThanOrEqual(4,$business->vehicles_count);
        $private=Customer::where('organization_id',$org->id)->where('type','private')->withCount('vehicles')->orderByDesc('vehicles_count')->firstOrFail();
        $this->assertGreaterThanOrEqual(2,$private->vehicles_count);
        $vehicleWithSeasons=Vehicle::where('organization_id',$org->id)->withCount('tireSets')->orderByDesc('tire_sets_count')->firstOrFail();
        $this->assertGreaterThanOrEqual(2,$vehicleWithSeasons->tire_sets_count);
        $this->assertGreaterThan(24,TireSet::where('organization_id',$org->id)->count());
    }

    public function test_demo_can_be_built_from_an_empty_installation_without_external_vehicle_api(): void
    {
        $this->artisan('demo:scale-inventory',['--sets'=>50])->assertSuccessful();
        $org=Organization::where('organization_number','DEMO-DEKKPILOT')->firstOrFail();
        $this->assertGreaterThanOrEqual(50,$org->vehicles()->count());
        $this->assertGreaterThan(50,$org->tireSets()->count());
        $this->assertGreaterThan(0,\App\Models\TireProduct::where('organization_id',$org->id)->count());
        $user=app(\App\Services\DemoAccessService::class)->prepare();
        $this->assertSame($org->id,$user->organization_id);
        $this->assertGreaterThan(0,\App\Models\Booking::where('organization_id',$org->id)->count());
    }

    public function test_reset_replaces_tampered_demo_but_never_touches_real_tenant(): void
    {
        $real=Organization::create(['public_id'=>Str::uuid(),'name'=>'Ekte Kunde AS','organization_number'=>'999888777']);
        $this->artisan('demo:reset',['--sets'=>50])->assertSuccessful();
        $demo=Organization::where('organization_number','DEMO-DEKKPILOT')->firstOrFail();
        Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$demo->id,'branch_id'=>$demo->branches()->value('id'),'customer_number'=>'HACKED','name'=>'Tuklet data']);
        $oldId=$demo->id;
        $this->artisan('demo:reset',['--sets'=>50])->assertSuccessful();
        $fresh=Organization::where('organization_number','DEMO-DEKKPILOT')->firstOrFail();
        $this->assertNotSame($oldId,$fresh->id);
        $this->assertDatabaseMissing('customers',['customer_number'=>'HACKED']);
        $this->assertDatabaseHas('organizations',['id'=>$real->id,'name'=>'Ekte Kunde AS']);
        $this->assertGreaterThanOrEqual(50,$fresh->vehicles()->count());
    }
}
