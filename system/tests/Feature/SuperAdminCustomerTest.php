<?php
namespace Tests\Feature;
use App\Models\{Organization,Branch,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
class SuperAdminCustomerTest extends TestCase
{
 use RefreshDatabase;
 private function user(bool $super=false):User {
  $org=Organization::create(['public_id'=>Str::uuid(),'name'=>'Verksted','email'=>'kontakt@example.no']);
  $branch=Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'name'=>'Hoved','code'=>'H']);
  return User::factory()->create(['organization_id'=>$org->id,'branch_id'=>$branch->id,'role'=>'owner','active'=>true,'is_super_admin'=>$super]);
 }
 public function test_only_superadmin_can_read_edit_or_close_customer():void {
  $owner=$this->user();$other=$this->user();$org=$other->organization;
  $this->actingAs($owner)->get(route('superadmin.customer',$org))->assertForbidden();
  $this->put(route('superadmin.customer.update',$org),['name'=>'Byttet'])->assertForbidden();
  $this->put(route('superadmin.customer.access',$org),['closed'=>1,'confirm'=>1])->assertForbidden();
  $this->assertNull($org->fresh()->suspended_at);
 }
 public function test_edit_is_validated_audited_and_does_not_change_subscription_or_login():void {
  $super=$this->user(true);$owner=$this->user();$org=$owner->organization;
  $this->actingAs($super)->get(route('superadmin.customer',$org))->assertOk()->assertSee('Kundeopplysninger')->assertSee('Ikke bekreftet');
  $data=['name'=>'Nytt verksted','email'=>'ny@example.no','phone'=>'12345678','organization_number'=>'987654321','subscription_status'=>'canceled'];
  $this->put(route('superadmin.customer.update',$org),$data)->assertSessionHasNoErrors();
  $this->assertSame('Nytt verksted',$org->fresh()->name);$this->assertSame('active',$org->fresh()->subscription_status);
  $this->assertNotSame('ny@example.no',$owner->fresh()->email);
  $this->assertDatabaseHas('audit_logs',['subject_id'=>$org->id,'action'=>'superadmin.customer.updated']);
  $this->put(route('superadmin.customer.update',$super->organization),$data)->assertSessionHasErrors('organization_number');
 }
 public function test_closing_blocks_existing_web_and_api_access_and_reopening_restores_it():void {
  $super=$this->user(true);$owner=$this->user();$org=$owner->organization;
  $token=Str::random(50);DB::table('personal_access_tokens')->insert(['user_id'=>$owner->id,'name'=>'Test','token_hash'=>hash('sha256',$token),'abilities'=>json_encode(['*']),'created_at'=>now()]);
  $this->actingAs($super)->put(route('superadmin.customer.access',$org),['closed'=>1,'confirm'=>1,'reason'=>'Avklaring'])->assertSessionHasNoErrors();
  $this->assertSame('active',$org->fresh()->subscription_status);
  $this->actingAs($owner)->get('/')->assertForbidden()->assertSee('Tilgangen er stengt');
  $this->get('/abonnement')->assertOk();
  $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
  $this->actingAs($super)->get(route('superadmin.customer',$org))->assertOk();
  $this->put(route('superadmin.customer.access',$org),['closed'=>0,'confirm'=>1])->assertSessionHasNoErrors();
  $this->actingAs($owner)->get('/')->assertOk();
  $this->withToken($token)->getJson('/api/v1/me')->assertOk();
 }
 public function test_payment_display_distinguishes_paid_free_and_unknown_and_can_filter_closed():void {
  $super=$this->user(true);$owner=$this->user();$org=$owner->organization;
  $this->actingAs($super)->get(route('superadmin.customer',$org))->assertSee('Ikke bekreftet');
  $org->update(['stripe_latest_invoice'=>['status'=>'paid','amount_paid'=>24900,'currency'=>'nok']]);
  $this->get(route('superadmin.customer',$org))->assertSee('Betalt')->assertSee('249,00 NOK innbetalt');
  $org->update(['stripe_latest_invoice'=>['status'=>'paid','amount_paid'=>0,'currency'=>'nok'],'suspended_at'=>now(),'name'=>'Stengt kunde']);
  $this->get(route('superadmin.customer',$org))->assertSee('Oppgjort – 0 kr');
  $this->get('/superadmin?access=closed')->assertSee('Stengt kunde');
  $this->get('/superadmin?access=open')->assertDontSee('Stengt kunde');
 }
}
