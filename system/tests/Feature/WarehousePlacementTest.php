<?php

namespace Tests\Feature;

use App\Models\{Branch, Customer, CustomerPortalToken, HotelAgreement, Organization, StorageLocation, TireSet, User, Vehicle};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehousePlacementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $org = Organization::create(['public_id'=>Str::uuid(), 'name'=>'Lager AS', 'subscription_status'=>'active']);
        $branch = Branch::create(['public_id'=>Str::uuid(), 'organization_id'=>$org->id, 'name'=>'Hoved', 'code'=>'H']);
        $user = User::factory()->create(['organization_id'=>$org->id, 'branch_id'=>$branch->id, 'role'=>'owner', 'active'=>true]);
        $customer = Customer::create(['public_id'=>Str::uuid(), 'organization_id'=>$org->id, 'branch_id'=>$branch->id, 'customer_number'=>'K1', 'name'=>'Lagerkunde']);
        $vehicle = Vehicle::create(['public_id'=>Str::uuid(), 'organization_id'=>$org->id, 'customer_id'=>$customer->id, 'registration_number'=>'AB12345']);
        $location = StorageLocation::create(['public_id'=>Str::uuid(), 'organization_id'=>$org->id, 'branch_id'=>$branch->id, 'code'=>'Rad 1', 'zone'=>'Lager', 'location_type'=>'rack', 'shelf_count'=>4, 'sets_per_shelf'=>15, 'capacity'=>60, 'active'=>true]);
        $set = TireSet::create(['public_id'=>Str::uuid(), 'organization_id'=>$org->id, 'vehicle_id'=>$vehicle->id, 'code'=>'HJ-'.Str::upper(Str::random(8)), 'season'=>'winter', 'kind'=>'complete_wheels', 'quantity'=>4, 'status'=>'received', 'received_at'=>now(), 'minimum_tread_depth'=>6, 'wash_status'=>'not_needed']);
        return compact('org', 'branch', 'user', 'customer', 'vehicle', 'location', 'set');
    }

    private function anotherSet(TireSet $set): TireSet
    {
        $copy = $set->replicate();
        $copy->public_id = Str::uuid();
        $copy->code = 'HJ-'.Str::upper(Str::random(8));
        $copy->save();
        return $copy;
    }

    private function placement(StorageLocation $location, int $length = 12, int $height = 3): array
    {
        return ['status'=>'stored', 'storage_location_id'=>$location->id, 'storage_position_number'=>$length, 'storage_shelf_number'=>$height];
    }

    public function test_manual_position_is_saved_and_visible_on_customer_map_and_single_label(): void
    {
        extract($this->fixture());
        $this->actingAs($user)->patch(route('tire-sets.status', $set), $this->placement($location))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tire_sets', ['id'=>$set->id, 'storage_position_number'=>12, 'storage_shelf_number'=>3]);
        foreach ([route('tire-sets.show',$set), route('inventory'), route('customers.show',$customer), route('warehouse.map',['q'=>$set->code])] as $url) {
            $this->get($url)->assertOk()->assertSee('Rad 1 · Lengde 12 · Høyde 3');
        }
        $response = $this->get(route('tire-sets.labels',['ids'=>$set->id]))->assertOk()->assertSee('Lengde 12')->assertSee('Høyde 3');
        $this->assertSame(1, substr_count($response->getContent(), '<article class="label '));
    }

    public function test_new_intake_accepts_manual_coordinates(): void
    {
        extract($this->fixture());
        $wheels = collect(['front_left','front_right','rear_left','rear_right'])->map(fn($position)=>['position'=>$position,'tread_depth_mm'=>6])->all();
        $this->actingAs($user)->post(route('tire-sets.store'), $this->placement($location,15,4)+['vehicle_id'=>$vehicle->id,'season'=>'summer','kind'=>'complete_wheels','wheels'=>$wheels])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tire_sets',['vehicle_id'=>$vehicle->id,'season'=>'summer','storage_position_number'=>15,'storage_shelf_number'=>4]);
    }

    public function test_occupied_slot_is_rejected_without_moving_either_set(): void
    {
        extract($this->fixture()); $other = $this->anotherSet($set);
        $this->actingAs($user)->patch(route('tire-sets.status',$set),$this->placement($location))->assertSessionHasNoErrors();
        $this->patch(route('tire-sets.status',$other),$this->placement($location))->assertSessionHasErrors('storage_location_id');
        $this->assertNull($other->fresh()->storage_location_id);
        $this->assertSame(12,$set->fresh()->storage_position_number);
    }

    public function test_dimensions_partial_coordinates_and_other_tenant_are_rejected(): void
    {
        extract($this->fixture()); $this->actingAs($user);
        $this->patch(route('tire-sets.status',$set),$this->placement($location,16,4))->assertSessionHasErrors('storage_location_id');
        $this->patch(route('tire-sets.status',$set),$this->placement($location,15,5))->assertSessionHasErrors('storage_location_id');
        $this->patch(route('tire-sets.status',$set),['status'=>'stored','storage_location_id'=>$location->id,'storage_position_number'=>4])->assertSessionHasErrors('storage_shelf_number');
        $other = $this->fixture();
        $this->patch(route('tire-sets.status',$set),$this->placement($other['location']))->assertNotFound();
    }

    public function test_auto_placement_uses_distinct_lengths_and_keeps_current_slot_on_full_rack(): void
    {
        extract($this->fixture()); $location->update(['shelf_count'=>1,'sets_per_shelf'=>2,'capacity'=>2]);
        $other=$this->anotherSet($set); $third=$this->anotherSet($set);
        $data=['status'=>'stored','storage_location_id'=>$location->id];
        $this->actingAs($user)->patch(route('tire-sets.status',$set),$data)->assertSessionHasNoErrors();
        $this->patch(route('tire-sets.status',$other),$data)->assertSessionHasNoErrors();
        $this->assertSame(1,$set->fresh()->storage_position_number); $this->assertSame(2,$other->fresh()->storage_position_number);
        $this->patch(route('tire-sets.status',$set),$data)->assertSessionHasNoErrors();
        $this->assertSame(1,$set->fresh()->storage_position_number);
        $this->patch(route('tire-sets.status',$third),$data)->assertSessionHasErrors('storage_location_id');
    }

    public function test_legacy_height_is_preserved_until_actual_length_is_entered(): void
    {
        extract($this->fixture());$set->update(['storage_location_id'=>$location->id,'storage_shelf_number'=>4,'status'=>'stored']);
        $this->actingAs($user)->patch(route('tire-sets.status',$set),['status'=>'stored','storage_location_id'=>$location->id])->assertSessionHasNoErrors();
        $this->assertSame(4,$set->fresh()->storage_shelf_number);$this->assertNull($set->fresh()->storage_position_number);
        $this->get(route('tire-sets.show',$set))->assertOk()->assertSee('Lengde ikke angitt');
        $this->patch(route('tire-sets.status',$set),$this->placement($location,15,4))->assertSessionHasNoErrors();
    }

    public function test_deleted_duplicate_disappears_from_portal_and_frees_its_slot_without_losing_history(): void
    {
        extract($this->fixture()); $other=$this->anotherSet($set);
        $this->actingAs($user)->patch(route('tire-sets.status',$set),$this->placement($location))->assertSessionHasNoErrors();
        $agreement=HotelAgreement::firstOrFail();
        $this->delete(route('tire-sets.destroy',$set),['confirmation'=>$set->code])->assertRedirect(route('customers.show',$customer));
        $this->assertSoftDeleted('tire_sets',['id'=>$set->id]);
        $this->assertDatabaseHas('storage_location_movements',['tire_set_id'=>$set->id]);
        $this->assertDatabaseHas('audit_logs',['subject_id'=>$set->id,'action'=>'tire_set.registration_deleted']);
        $this->assertDatabaseHas('hotel_agreements',['id'=>$agreement->id,'status'=>'active']);
        $this->assertSame(1,$vehicle->fresh()->tireSets()->count());
        $this->flushSession(); // Do not mistake the deletion confirmation for a remaining inventory row.
        $this->get(route('inventory'))->assertOk()->assertDontSee($set->code);
        $this->get(route('customers.show',$customer))->assertOk()->assertDontSee($set->code);
        $plain=Str::random(64);CustomerPortalToken::create(['organization_id'=>$org->id,'customer_id'=>$customer->id,'token_hash'=>hash('sha256',$plain),'expires_at'=>now()->addDay()]);
        $this->get(route('portal.show',$plain))->assertOk()->assertDontSee($set->code);
        $this->get(route('tire-sets.show',$set))->assertNotFound();
        $this->patch(route('tire-sets.status',$other),$this->placement($location))->assertSessionHasNoErrors();
    }

    public function test_deletion_requires_confirmation_permission_and_matching_tenant(): void
    {
        extract($this->fixture());
        $this->actingAs($user)->delete(route('tire-sets.destroy',$set))->assertSessionHasErrors('confirmation');
        $user->update(['role'=>'technician']);
        $this->delete(route('tire-sets.destroy',$set),['confirmation'=>$set->code])->assertForbidden();
        $other=$this->fixture();
        $this->actingAs($other['user'])->delete(route('tire-sets.destroy',$set),['confirmation'=>$set->code])->assertNotFound();
        $this->assertNotSoftDeleted($set);
    }

    public function test_batch_print_has_one_label_per_set_and_excludes_deleted_sets(): void
    {
        extract($this->fixture());$other=$this->anotherSet($set);$deleted=$this->anotherSet($set);$deleted->delete();
        $response=$this->actingAs($user)->get(route('tire-sets.labels',['ids'=>implode(',',[$set->id,$other->id,$deleted->id])]))->assertOk();
        $this->assertSame(2,substr_count($response->getContent(),'<article class="label '));
        $response->assertDontSee($deleted->code);
    }

    public function test_rack_cannot_shrink_past_an_occupied_length_or_height():void
    {
        extract($this->fixture());$this->actingAs($user)->patch(route('tire-sets.status',$set),$this->placement($location));
        $data=$location->only(['code','zone','location_type','shelf_count','sets_per_shelf']);$data['sets_per_shelf']=11;
        $this->put(route('admin.warehouse.update',$location),$data)->assertSessionHasErrors('location');
        $this->assertSame(15,$location->fresh()->sets_per_shelf);
    }

    public function test_workday_intake_and_action_center_use_the_manual_position(): void
    {
        extract($this->fixture());$other=$this->anotherSet($set);
        $this->actingAs($user)->patch(route('workday.intake-step',$set),$this->placement($location,10,2)+['action'=>'place'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tire_sets',['id'=>$set->id,'storage_position_number'=>10,'storage_shelf_number'=>2]);
        $this->put(route('actions.tire-set.update',$other),$this->placement($location,11,2)+['wash_status'=>'not_needed'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tire_sets',['id'=>$other->id,'storage_position_number'=>11,'storage_shelf_number'=>2]);
    }

    public function test_api_uses_same_occupancy_checks_and_returns_coordinates():void
    {
        extract($this->fixture());$other=$this->anotherSet($set);$plain=Str::random(64);
        DB::table('personal_access_tokens')->insert(['user_id'=>$user->id,'name'=>'scanner','token_hash'=>hash('sha256',$plain),'abilities'=>json_encode(['read','workshop.write']),'created_at'=>now(),'updated_at'=>now()]);
        $this->withToken($plain)->patchJson('/api/v1/tire-sets/'.$set->id.'/status',$this->placement($location))->assertOk()->assertJsonPath('data.storage_position_number',12);
        $this->withToken($plain)->patchJson('/api/v1/tire-sets/'.$other->id.'/status',$this->placement($location))->assertUnprocessable();
    }
}
