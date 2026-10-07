<?php
namespace Tests\Feature;
use App\Models\{Branch,Customer,Organization,StorageLocation,TireSet,User,Vehicle};
use App\Services\{LabelSettings,TireInspectionService,TreadAssessment};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
class HotelWorkflowTest extends TestCase {
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


    public function test_vehicle_details_can_be_corrected_without_changing_owner_or_history(): void {
        extract($this->fixture());
        $agreement = $vehicle->hotelAgreements()->firstOrFail();
        $this->actingAs($user)->get(route('vehicles.edit',$vehicle))->assertOk()->assertSee('Lagre bilopplysninger');
        $this->get(route('customers.show',$customer))->assertOk()->assertSee(route('vehicles.edit',$vehicle),false);
        $this->get(route('vehicles.history',$vehicle))->assertOk()->assertSee(route('vehicles.edit',$vehicle),false);
        $this->put(route('vehicles.update',$vehicle),['registration_number'=>'xy 54321','make'=>'Volvo','model'=>'V70','model_year'=>2015,'mileage'=>123456,'vin'=>'VIN123','recommended_tire_size'=>'205/55R16','notes'=>'Korrigert','customer_id'=>99999,'organization_id'=>99999])->assertSessionHasNoErrors()->assertRedirect(route('vehicles.history',$vehicle));
        $vehicle->refresh();
        $this->assertSame('XY54321',$vehicle->registration_number);
        $this->assertSame('Volvo',$vehicle->make);
        $this->assertSame($customer->id,$vehicle->customer_id);
        $this->assertSame($org->id,$vehicle->organization_id);
        $this->assertSame($vehicle->id,$set->fresh()->vehicle_id);
        $this->assertSame('active',$agreement->fresh()->status);
        $this->assertDatabaseHas('audit_logs',['action'=>'vehicle.updated','subject_id'=>$vehicle->id]);
        $copy=$vehicle->replicate();$copy->public_id=Str::uuid();$copy->registration_number='ZZ99999';$copy->save();
        $this->from(route('vehicles.edit',$vehicle))->put(route('vehicles.update',$vehicle),['registration_number'=>'zz 99999'])->assertSessionHasErrors('registration_number');
        $this->assertSame('XY54321',$vehicle->fresh()->registration_number);
        $this->put(route('vehicles.update',$vehicle),['registration_number'=>'XY54321','model_year'=>1800,'mileage'=>-1])->assertSessionHasErrors(['model_year','mileage']);
        $other=$this->fixture();
        $this->get(route('vehicles.edit',$other['vehicle']))->assertNotFound();
        $this->put(route('vehicles.update',$other['vehicle']),['registration_number'=>'AB99999'])->assertNotFound();
        $user->update(['role'=>'technician']);
        $this->get(route('vehicles.edit',$vehicle))->assertForbidden();
        $this->put(route('vehicles.update',$vehicle),['registration_number'=>'AB99999'])->assertForbidden();
    }

    public function test_agreement_can_be_ended_after_tires_are_removed_and_no_longer_renews(): void {
        extract($this->fixture());
        $agreement=$vehicle->hotelAgreements()->firstOrFail();
        $set->delete();
        $agreement->update(['renews_on'=>today()->subDay()]);
        $this->actingAs($user)->get(route('hotel-agreements.index'))->assertOk()->assertSee('Lagre status')->assertDontSee('onchange=',false);
        $this->from(route('hotel-agreements.index'))->patch(route('hotel-agreements.status',$agreement),['status'=>'ended'])->assertSessionHasNoErrors()->assertRedirect(route('hotel-agreements.index'));
        $this->assertSame('ended',$agreement->fresh()->status);
        $this->assertTrue($agreement->fresh()->ends_on->isToday());
        $this->assertSame(0,app(\App\Services\HotelChargeService::class)->generate($org->id));
        $this->get(route('hotel-agreements.index',['status'=>'ended']))->assertOk()->assertSee('value="ended" selected',false);
        $other=$this->fixture();
        $this->patch(route('hotel-agreements.status',$other['vehicle']->hotelAgreements()->firstOrFail()),['status'=>'ended'])->assertNotFound();
    }

    private function incoming(TireSet $out): TireSet {
        $set=$out->replicate();$set->public_id=Str::uuid();$set->code='HJ-'.Str::upper(Str::random(8));$set->status='delivered';$set->delivered_at=now();$set->season='summer';$set->save();return $set;
    }
    private function exchangeData($location,$incoming): array {
        return ['confirm'=>1,'incoming_mode'=>'existing','incoming_id'=>$incoming->id,'storage_location_id'=>$location->id,'storage_shelf_number'=>1,'storage_position_number'=>1];
    }
    public function test_settings_navigation_opens_only_selected_area_and_handles_invalid_tabs(): void {
        extract($this->fixture());$this->actingAs($user);
        foreach(['team'=>'Ansatte og roller','capacity'=>'Arbeidsbukker og tilgjengelighet','services'=>'Tjenestekatalog','products'=>'Dekk og priser','labels'=>'Etikettutskrift','integrations'=>'Statens vegvesen'] as $tab=>$heading) {
            $page=$this->get(route('admin.settings',['tab'=>$tab]))->assertOk()->assertSee('<h2>'.$heading.'</h2>',false)->assertSee('aria-current="page"',false);
            if($tab!=='team') $page->assertDontSee('name="password"',false);
            if($tab!=='integrations') $page->assertDontSee('name="api_key"',false);
        }
        $this->get(route('admin.settings',['tab'=>['bad']]))->assertOk()->assertSee('<h2>Ansatte og roller</h2>',false);
    }
    public function test_superadmin_is_not_tenant_staff_but_can_still_enter_customer(): void {
        extract($this->fixture());
        $support=User::factory()->create(['organization_id'=>$org->id,'branch_id'=>$branch->id,'name'=>'SUPPORT-HIDDEN-ACCOUNT','email'=>'support-hidden@example.no','is_super_admin'=>true,'role'=>'admin','active'=>true]);
        $this->actingAs($user)->get(route('admin.settings'))->assertOk()->assertDontSee('SUPPORT-HIDDEN-ACCOUNT')->assertDontSee('support-hidden@example.no');
        $this->get(route('admin'))->assertOk()->assertSee('1 ansatte')->assertSee(route('admin.labels'),false);
        $this->get(route('bookings'))->assertOk()->assertDontSee('SUPPORT-HIDDEN-ACCOUNT');
        $this->put(route('admin.employees.update',$support),['name'=>'Changed','email'=>'changed@example.no','role'=>'admin'])->assertNotFound();
        $this->patch(route('admin.employees.toggle',$support))->assertNotFound();
        $this->assertTrue($support->fresh()->active);
        $other=$this->fixture();
        $this->actingAs($support)->post(route('superadmin.enter',$other['org']))->assertRedirect();
        $this->get(route('admin.settings'))->assertOk()->assertSee('Du arbeider som superadmin');
        $this->assertSame($org->id,$support->fresh()->organization_id);
    }
    public function test_removing_rack_preserves_history_and_rejects_occupied_or_foreign_racks(): void {
        extract($this->fixture());$this->actingAs($user);
        $set->update(['storage_location_id'=>$location->id]);
        $this->delete(route('admin.warehouse.archive',$location),['confirmation'=>$location->code])->assertSessionHasErrors('location');
        $set->update(['status'=>'delivered']);
        $this->delete(route('admin.warehouse.archive',$location),['confirmation'=>'wrong'])->assertSessionHasErrors('confirmation');
        $this->delete(route('admin.warehouse.archive',$location),['confirmation'=>$location->code])->assertRedirect();
        $this->assertNotNull($location->fresh()->archived_at);$this->assertFalse($location->fresh()->active);
        $this->assertSame($location->id,$set->fresh()->storage_location_id);
        $this->get(route('admin.warehouse'))->assertOk()->assertDontSee('id="location-'.$location->id.'"',false);
        $other=$this->fixture();$this->delete(route('admin.warehouse.archive',$other['location']),['confirmation'=>$other['location']->code])->assertNotFound();
        $this->patch(route('admin.warehouse.toggle',$location))->assertNotFound();
    }
    public function test_measurements_show_all_wheels_zero_depth_and_damage_and_are_scoped(): void {
        extract($this->fixture());$inspection=app(TireInspectionService::class)->recordUniform($set,0,$user->id);
        $inspection->measurements()->first()->update(['uneven_wear'=>true,'tire_damage'=>true,'notes'=>'Skade på skulder']);
        $this->actingAs($user)->get(route('tire-sets.measurements',$set))->assertOk()->assertSee('Venstre foran')->assertSee('Høyre bak')->assertSee('Skade på skulder')->assertSee('Ujevn slitasje')->assertSee('0.0');
        $this->get(route('tire-sets.show',$set))->assertSee(route('tire-sets.measurements',$set),false);
        $other=$this->fixture();$this->get(route('tire-sets.measurements',$other['set']))->assertNotFound();
    }
    public function test_winter_four_mm_is_attention_for_recording_and_inventory(): void {
        extract($this->fixture());$inspection=app(TireInspectionService::class)->recordUniform($set,4,$user->id);
        $this->assertSame('attention',$inspection->overall_status);$this->assertSame('good',TreadAssessment::status(4,'summer'));
        $this->actingAs($user)->get(route('inventory',['condition'=>'attention']))->assertOk()->assertSee($vehicle->registration_number)->assertSee('status tread-attention',false);
        $this->get(route('inventory',['condition'=>'good']))->assertDontSee($set->code);
    }
    public function test_comments_and_winter_type_survive_inspections_and_contact_is_per_vehicle(): void {
        extract($this->fixture());$this->actingAs($user);
        $this->put(route('tire-sets.details',$set),['winter_type'=>'studded','hotel_notes'=>'Låsebolt i pose'])->assertSessionHasNoErrors();
        app(TireInspectionService::class)->recordUniform($set,6,$user->id,null,'Kontrollnotat');
        $this->assertSame('Låsebolt i pose',$set->fresh()->hotel_notes);
        $this->get(route('inventory'))->assertSee('Pigg');
        $this->put(route('vehicles.contact',$vehicle),['contact_name'=>'Sjåfør','contact_phone'=>'12345678'])->assertSessionHasNoErrors();
        $this->get(route('vehicles.history',$vehicle))->assertOk()->assertSee('Sjåfør');
        $this->get(route('customers.show',$customer))->assertOk()->assertSee('Sjåfør');
        $other=$this->fixture();$this->put(route('vehicles.contact',$other['vehicle']),['contact_name'=>'Ulovlig'])->assertNotFound();
        $this->put(route('tire-sets.details',$other['set']),['hotel_notes'=>'Ulovlig'])->assertNotFound();
    }
    public function test_label_templates_are_per_organization_with_qr_toggle_and_print_dimensions(): void {
        extract($this->fixture());$this->actingAs($user);
        $this->get(route('admin.labels'))->assertOk()->assertSee('Tydelig lagerplass')->assertSee('iframe',false);
        $data=['template'=>'location','size'=>'110x74','show_qr'=>0,'text_scale'=>150];
        $this->put(route('admin.labels.save'),$data)->assertSessionHasNoErrors();
        $this->get(route('tire-sets.labels',['ids'=>$set->id]))->assertOk()->assertSee('size:110mm 74mm',false)->assertSee('label-location')->assertDontSee('<img class="qr-code"',false);
        $this->assertSame('location',LabelSettings::forOrganization($org->id)['template']);
        foreach(['standard','location','simple'] as $template) $this->get(route('admin.labels.preview',[...$data,'template'=>$template]))->assertOk()->assertHeader('X-Frame-Options','SAMEORIGIN')->assertSee('HJ-EKSEMPEL');
        $other=$this->fixture();$this->assertSame('standard',LabelSettings::forOrganization($other['org']->id)['template']);
        $this->actingAs($other['user'])->get(route('tire-sets.labels',['ids'=>$other['set']->id]))->assertOk()->assertSee('<img class="qr-code"',false)->assertSee('size:100mm 50mm',false);
        $this->put(route('admin.labels.save'),[...$data,'size'=>'malicious'])->assertSessionHasErrors('size');
    }
    public function test_season_exchange_reuses_slot_preserves_history_and_rejects_repeats(): void {
        extract($this->fixture());$set->update(['status'=>'stored','storage_location_id'=>$location->id,'storage_shelf_number'=>1,'storage_position_number'=>1]);
        $in=$this->incoming($set);app(TireInspectionService::class)->recordUniform($in,5,$user->id);
        $this->actingAs($user)->get(route('tire-sets.exchange',$set))->assertOk()->assertSee($in->code);
        $data=$this->exchangeData($location,$in);
        DB::table('hotel_agreements')->where('vehicle_id',$vehicle->id)->update(['status'=>'paused']);
        $this->post(route('tire-sets.exchange.store',$set),$data)->assertSessionHasNoErrors()->assertRedirect(route('tire-sets.show',$in));
        $this->assertSame('delivered',$set->fresh()->status);$this->assertNull($set->fresh()->storage_location_id);
        $this->assertSame('stored',$in->fresh()->status);$this->assertSame(1,$in->fresh()->storage_position_number);$this->assertNull($in->fresh()->minimum_tread_depth);
        $this->assertSame(1,$in->inspections()->count());$this->assertDatabaseCount('storage_location_movements',2);$this->assertDatabaseCount('hotel_agreements',1);$this->assertSame('paused',DB::table('hotel_agreements')->where('vehicle_id',$vehicle->id)->value('status'));
        $this->post(route('tire-sets.exchange.store',$set),$data)->assertSessionHasErrors('exchange');$this->assertDatabaseCount('storage_location_movements',2);
    }
    public function test_failed_exchange_rolls_back_delivery_and_rejects_other_vehicle_and_tenant(): void {
        extract($this->fixture());$in=$this->incoming($set);$occupied=$set->replicate();$occupied->public_id=Str::uuid();$occupied->code='HJ-OCCUPIED';$occupied->storage_location_id=$location->id;$occupied->storage_shelf_number=1;$occupied->storage_position_number=1;$occupied->save();
        $this->actingAs($user);$data=$this->exchangeData($location,$in);
        $this->post(route('tire-sets.exchange.store',$set),$data)->assertSessionHasErrors('storage_location_id');
        $this->assertSame('received',$set->fresh()->status);$this->assertSame('delivered',$in->fresh()->status);$this->assertDatabaseCount('storage_location_movements',0);
        $other=$this->fixture();$this->post(route('tire-sets.exchange.store',$set),[...$data,'incoming_id'=>$other['set']->id])->assertNotFound();
        $this->post(route('tire-sets.exchange.store',$other['set']),$data)->assertNotFound();
    }
    public function test_exchange_can_receive_brand_new_set_and_requires_four_actual_measurements(): void {
        extract($this->fixture());$this->actingAs($user);
        $data=['confirm'=>1,'incoming_mode'=>'new','season'=>'winter','winter_type'=>'unstudded','hotel_notes'=>'Nytt sett','storage_location_id'=>$location->id];
        $this->post(route('tire-sets.exchange.store',$set),$data)->assertSessionHasErrors('wheels');
        $data['wheels']=array_map(fn($p)=>['position'=>$p,'tread_depth_mm'=>7],['front_left','front_right','rear_left','rear_right']);
        $this->post(route('tire-sets.exchange.store',$set),$data)->assertRedirect();
        $new=TireSet::where('id','!=',$set->id)->firstOrFail();$this->assertSame('unstudded',$new->winter_type);$this->assertSame('Nytt sett',$new->hotel_notes);$this->assertSame('7.0',$new->minimum_tread_depth);$this->assertDatabaseCount('wheel_measurements',4);
    }
    public function test_intake_and_four_wheel_control_use_winter_threshold_and_keep_set_comments(): void {
        extract($this->fixture());$this->actingAs($user);
        $wheels=array_map(fn($p)=>['position'=>$p,'tread_depth_mm'=>4,'tpms_status'=>'not_checked'],['front_left','front_right','rear_left','rear_right']);
        $this->post(route('tire-sets.store'),['vehicle_id'=>$vehicle->id,'season'=>'winter','winter_type'=>'unstudded','hotel_notes'=>'Original kommentar','kind'=>'complete_wheels','wheels'=>$wheels])->assertSessionHasNoErrors();
        $new=TireSet::latest('id')->first();$this->assertSame('attention',$new->inspections()->first()->overall_status);
        $this->post(route('tire-sets.inspection.store',$new),['wheels'=>$wheels,'notes'=>'Kontroll 2'])->assertSessionHasNoErrors();
        $this->assertSame('Original kommentar',$new->fresh()->hotel_notes);$this->assertSame(2,$new->inspections()->where('overall_status','attention')->count());
    }
    public function test_csv_is_excel_compatible_scoped_and_excludes_delivered_by_default(): void {
        extract($this->fixture());$set->update(['hotel_notes'=>'=FORMULA()']);$vehicle->update(['contact_name'=>'Sjåfør','contact_phone'=>'12345678']);$in=$this->incoming($set);$other=$this->fixture();
        $response=$this->actingAs($user)->get(route('inventory.export'))->assertOk();$csv=$response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF",$csv);$this->assertStringContainsString('Sjåfør',$csv);$this->assertStringContainsString("'=FORMULA()",$csv);$this->assertStringNotContainsString($in->code,$csv);$this->assertStringNotContainsString($other['set']->code,$csv);
        $user->update(['role'=>'technician']);$this->get(route('inventory.export'))->assertForbidden();$this->put(route('admin.labels.save'),LabelSettings::defaults())->assertForbidden();
    }
}
