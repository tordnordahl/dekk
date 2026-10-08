<?php
namespace Tests\Feature;
use App\Models\{Organization,Branch,User,Customer,Vehicle,TireSet,TireProduct,StockReservation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TireEditingTest extends TestCase {
    use RefreshDatabase;
    protected function fixture(): array {
        $org=Organization::create(['public_id'=>Str::uuid(),'name'=>'Dekkbutikk','subscription_status'=>'active']);
        $branch=Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'name'=>'Hoved','code'=>'H']);
        $user=User::factory()->create(['organization_id'=>$org->id,'branch_id'=>$branch->id,'role'=>'owner','active'=>true]);
        $customer=Customer::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'branch_id'=>$branch->id,'customer_number'=>'K1','name'=>'Kunde']);
        $vehicle=Vehicle::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'customer_id'=>$customer->id,'registration_number'=>'AB12345']);
        $set=TireSet::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'vehicle_id'=>$vehicle->id,'code'=>'HJ-1','manufacturer'=>'Feil merke','size'=>'205/55 R16','season'=>'winter','winter_type'=>'studded','kind'=>'complete_wheels','minimum_tread_depth'=>5,'status'=>'stored','received_at'=>now()]);
        $product=TireProduct::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'sku'=>'SKU-1','brand'=>'Nokian','model'=>'Hakkapeliitta','size'=>'205/55 R16','season'=>'winter','studded'=>true,'price_cents'=>149900,'stock_quantity'=>8,'active'=>true]);
        return compact('org','branch','user','customer','vehicle','set','product');
    }
    private function data(): array {return ['sku'=>'SKU-1','brand'=>'Michelin','model'=>'Pilot','size'=>'225/45 R17','season'=>'summer','studded'=>1,'price'=>'1299.50','cost'=>'800.20','stock_quantity'=>8,'active'=>1];}
    public function test_customer_tires_can_be_corrected_without_reactivating_agreements_or_moving_stock(): void {
        extract($this->fixture());$agreement=$vehicle->hotelAgreements()->firstOrFail();$agreement->update(['status'=>'paused']);
        $this->actingAs($user)->get(route('tire-sets.show',$set))->assertOk()->assertSee('data-edit-open="edit-tire-set"',false)->assertSee('Slett feilregistrert hjulsett');
        $this->put(route('tire-sets.details',$set),['manufacturer'=>'Michelin','size'=>'225/45 R17','season'=>'summer','kind'=>'tires','dot_year'=>2025,'winter_type'=>'studded','hotel_notes'=>'Rettet','status'=>'delivered','vehicle_id'=>99999])->assertSessionHasNoErrors();
        $set->refresh();$this->assertSame('Michelin',$set->manufacturer);$this->assertSame('225/45 R17',$set->size);$this->assertNull($set->winter_type);
        $this->assertSame('stored',$set->status);$this->assertSame($vehicle->id,$set->vehicle_id);$this->assertEquals(5,$set->minimum_tread_depth);
        $this->assertSame('paused',$agreement->fresh()->status);$this->assertDatabaseCount('hotel_agreements',1);
        $this->put(route('tire-sets.details',$set),['season'=>'wrong','dot_year'=>1])->assertSessionHasErrorsIn('tireDetails',['season','dot_year']);
        $this->get(route('tire-sets.show',$set))->assertSee('data-reopen',false);
        $other=$this->fixture();$this->put(route('tire-sets.details',$other['set']),['manufacturer'=>'Wrong'])->assertNotFound();
    }
    public function test_catalog_edits_are_scoped_validated_and_protect_reserved_stock(): void {
        extract($this->fixture());$this->actingAs($user);
        $this->put(route('admin.products.update',$product),$this->data()+['organization_id'=>99999])->assertSessionHasNoErrors();
        $product->refresh();$this->assertSame('Michelin',$product->brand);$this->assertSame(129950,$product->price_cents);$this->assertSame(80020,$product->cost_cents);$this->assertFalse($product->studded);$this->assertSame($org->id,$product->organization_id);
        $reservation=StockReservation::create(['organization_id'=>$org->id,'tire_product_id'=>$product->id,'quantity'=>4,'status'=>'reserved']);
        foreach([['stock_quantity'=>3],['active'=>0]] as $changes) $this->put(route('admin.products.update',$product),array_replace($this->data(),$changes))->assertSessionHasErrorsIn('product'.$product->id,'stock_quantity');
        $reservation->update(['status'=>'released']);
        $this->put(route('admin.products.update',$product),array_replace($this->data(),['active'=>0]))->assertSessionHasNoErrors();
        $this->assertFalse($product->fresh()->active);$this->assertNull($product->fresh()->deleted_at);
        $other=$this->fixture();$this->put(route('admin.products.update',$other['product']),$this->data())->assertNotFound();
        $duplicate=$product->replicate();$duplicate->public_id=Str::uuid();$duplicate->sku='TAKEN';$duplicate->save();
        $this->put(route('admin.products.update',$product),array_replace($this->data(),['sku'=>'TAKEN']))->assertSessionHasErrorsIn('product'.$product->id,'sku');
        $user->update(['role'=>'warehouse']);$this->put(route('admin.products.update',$product),$this->data())->assertForbidden();
    }
    public function test_ajax_catalog_search_covers_all_fields_old_products_and_tenant_isolation(): void {
        extract($this->fixture());$other=$this->fixture();$other['product']->update(['brand'=>'HiddenBrand']);
        for($i=0;$i<24;$i++) {$p=$product->replicate();$p->public_id=Str::uuid();$p->sku='OTHER-'.$i;$p->brand='Other';$p->model='Other';$p->size='111/11 R11';$p->save();}
        $this->actingAs($user);
        foreach(['SKU-1','Nokian','Hakkapeliitta','205/55','Nokian 205/55','205/55R16'] as $query) {
            $r=$this->getJson(route('admin.settings',['tab'=>'products','product_q'=>$query]))->assertOk();
            $this->assertStringContainsString('edit-product-'.$product->id,$r->json('html'));$this->assertStringNotContainsString('HiddenBrand',$r->json('html'));
        }
        $r=$this->getJson(route('admin.settings',['tab'=>'products','products_page'=>2]))->assertOk();$this->assertStringContainsString('edit-product-'.$product->id,$r->json('html'));
        $this->get(route('admin.settings',['tab'=>'products','product_q'=>'Nokian']))->assertOk()->assertSee('data-product-search',false)->assertSee('SKU-1');
        $r=$this->getJson(route('admin.settings',['tab'=>'products','product_q'=>'NoSuchBrand']))->assertOk();$this->assertStringContainsString('Ingen varer funnet',$r->json('html'));
    }
    public function test_workday_opens_exact_booking_and_preserves_demo_and_tenant_boundaries(): void
    {
        extract($this->fixture());
        $create = fn($reference, $start) => \App\Models\Booking::create([
            'public_id'=>Str::uuid(), 'organization_id'=>$org->id, 'branch_id'=>$branch->id,
            'assigned_user_id'=>$user->id, 'customer_id'=>$customer->id, 'vehicle_id'=>$vehicle->id,
            'reference'=>$reference, 'service_name'=>$reference, 'starts_at'=>$start,
            'ends_at'=>$start->copy()->addMinutes(30), 'status'=>'scheduled',
        ]);
        for ($i=0;$i<31;$i++) $create('Earlier '.$i,today()->addMinutes($i));
        $booking=$create('Selected appointment',today()->addHours(15));
        $this->actingAs($user);
        foreach (['mine','all','bays'] as $mode) {
            $this->get(route('workday',['area'=>'schedule','schedule'=>$mode]))
                ->assertOk()->assertSee(route('bookings',['booking'=>$booking->id]).'#booking-'.$booking->id,false);
        }
        $this->get(route('bookings',['booking'=>$booking->id]))
            ->assertOk()->assertSee('Selected appointment')->assertDontSee('Earlier 0');
        $past=$create('Past appointment',today()->subDays(2));
        $this->get(route('bookings',['booking'=>$past->id]))->assertOk()->assertSee('Past appointment');
        $this->withSession(['demo_read_only'=>true])->get(route('bookings',['booking'=>$booking->id]))->assertOk();
        $this->post(route('bookings.complete',$booking))->assertSessionHasErrors('demo');
        $this->assertSame('scheduled',$booking->fresh()->status);
        $other=$this->fixture();
        $this->actingAs($other['user'])->get(route('bookings',['booking'=>$booking->id]))->assertNotFound();
    }

    public function test_brand_catalog_has_defaults_and_private_idempotent_custom_brands(): void {
        extract($this->fixture());
        $this->actingAs($user)->get(route('admin.settings',['tab'=>'products']))
            ->assertOk()->assertSee('Dekkmerker')->assertSee('Continental')->assertSee('Nokian');
        $this->post(route('admin.brands.store'),['brand_name'=>'Local Brand'])->assertSessionHasNoErrors();
        $this->post(route('admin.brands.store'),['brand_name'=>' local brand '])->assertSessionHasNoErrors();
        $this->post(route('admin.brands.store'),['brand_name'=>'nokian'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tire_brands',1);
        $this->getJson(route('admin.settings',['tab'=>'products','product_q'=>'Nokian']))
            ->assertOk()->assertSee('Local Brand');
        $other=$this->fixture();
        $this->actingAs($other['user'])->get(route('admin.settings',['tab'=>'products']))
            ->assertOk()->assertDontSee('Local Brand')->assertSee('Continental');
        $this->post(route('admin.brands.store'),['brand_name'=>'Local Brand'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tire_brands',2);
        $this->post(route('admin.brands.store'),['brand_name'=>''])->assertSessionHasErrorsIn('tireBrands','brand_name');
        $other['user']->update(['role'=>'warehouse']);
        $this->post(route('admin.brands.store'),['brand_name'=>'Forbidden'])->assertForbidden();
    }

    public function test_brands_can_be_renamed_or_removed_without_losing_products_or_crossing_tenants(): void {
        extract($this->fixture());
        $other=$this->fixture();
        $this->actingAs($user);
        $this->patch(route('admin.brands.change'),['action'=>'rename','original_name'=>'Nokian','brand_name'=>'Nokian Tyres'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Nokian Tyres',$product->fresh()->brand);
        $this->assertSame('Nokian',$other['product']->fresh()->brand);
        $catalog=app(\App\Services\TireBrandCatalog::class);
        $this->assertFalse($catalog->names($org->id)->contains('Nokian'));
        $this->assertTrue($catalog->names($other['org']->id)->contains('Nokian'));
        $this->patch(route('admin.brands.change'),['action'=>'rename','original_name'=>'Nokian Tyres','brand_name'=>'Continental'])
            ->assertSessionHasErrorsIn('tireBrands','brand_name');
        $this->patch(route('admin.brands.change'),['action'=>'delete','original_name'=>'Nokian Tyres'])
            ->assertSessionHasErrorsIn('tireBrands','confirm');
        $this->patch(route('admin.brands.change'),['action'=>'delete','original_name'=>'Nokian Tyres','confirm'=>1])
            ->assertSessionHasNoErrors();
        $this->assertFalse($catalog->names($org->id)->contains('Nokian Tyres'));
        $this->assertSame('Nokian Tyres',$product->fresh()->brand);
        $this->getJson(route('admin.settings',['tab'=>'products']))->assertOk()->assertSee('Nokian Tyres');
        $this->post(route('admin.brands.store'),['brand_name'=>'Nokian Tyres'])->assertSessionHasNoErrors();
        $this->assertTrue($catalog->names($org->id)->contains('Nokian Tyres'));
        $this->patch(route('admin.brands.change'),['action'=>'delete','original_name'=>'Continental','confirm'=>1])->assertSessionHasNoErrors();
        $this->assertFalse($catalog->names($org->id)->contains('Continental'));
        $this->assertTrue($catalog->names($other['org']->id)->contains('Continental'));
        $this->actingAs($other['user'])->patch(route('admin.brands.change'),['action'=>'delete','original_name'=>'Nokian Tyres','confirm'=>1])->assertNotFound();
        $user->update(['role'=>'warehouse']);
        $this->actingAs($user)->patch(route('admin.brands.change'),['action'=>'delete','original_name'=>'Michelin','confirm'=>1])->assertForbidden();
    }
}
