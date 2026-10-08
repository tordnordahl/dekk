<?php
namespace Tests\Feature;
use App\Models\{Organization,Branch,User,ServiceAgreement};
use App\Services\ServiceAgreements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;
class ServiceAgreementTest extends TestCase
{
 use RefreshDatabase;
 protected function person(string $role='owner', ?Organization $org=null, bool $super=false): User {
  $org??=Organization::create(['public_id'=>Str::uuid(),'name'=>'Eksempel Dekk AS','organization_number'=>(string)random_int(100000000,999999998),'subscription_status'=>'active']);
  $branch=$org->branches()->first()??Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'name'=>'Hoved','code'=>'H','active'=>true]);
  return User::factory()->create(['organization_id'=>$org->id,'branch_id'=>$branch->id,'name'=>'Kari Eksempel','role'=>$role,'active'=>true,'is_super_admin'=>$super]);
 }
 protected function payload(): array {
  return array_replace(require resource_path('legal/agreement-draft.php'),[
   'supplier_name'=>'Testleverandør AS','supplier_number'=>'123456789','supplier_address'=>'Testveien 1, 0001 Oslo',
   'processors'=>'Kun syntetisk testleverandør. Ingen virkelige kunder eller data.',
   'deletion'=>'Kun testdata: slettes ved avsluttet test. Backuper maksimalt 14 dager.',
   'base_version'=>app(ServiceAgreements::class)->current()?->id??0,'confirm'=>1,
  ]);
 }
 protected function publish(): ServiceAgreement {
  $this->actingAs($this->person(super:true))->post(route('superadmin.agreements.publish'),$this->payload())->assertSessionHasNoErrors()->assertRedirect();
  return app(ServiceAgreements::class)->current();
 }
 protected function consent(ServiceAgreement $agreement): array { return ['agreement'=>1,'agreement_id'=>$agreement->id,'agreement_hash'=>$agreement->sha256]; }
 public function test_existing_admin_is_gated_staff_continue_and_consent_is_org_scoped_and_idempotent(): void {
  $owner=$this->person();$admin=$this->person('admin',$owner->organization);$staff=$this->person('technician',$owner->organization);$other=$this->person();
  $this->actingAs($owner)->get('/')->assertOk();
  $agreement=$this->publish();
  $this->actingAs($owner)->get('/')->assertRedirect(route('agreement.required'));
  $this->get('/abonnement')->assertOk();
  $this->postJson('/kunder',[])->assertStatus(423)->assertJsonPath('redirect',route('agreement.required'));
  $this->get(route('agreement.required'))->assertOk()->assertSee('role="dialog"',false)->assertSee('fullmakt');
 }
 public function test_acceptance_records_exact_version_and_does_not_bind_other_tenants(): void {
  $owner=$this->person();$org=$owner->organization;$admin=$this->person('admin',$org);$staff=$this->person('technician',$org);$other=$this->person();
  $agreement=$this->publish();
  $this->actingAs($staff)->get('/')->assertOk();
  $this->post(route('agreement.accept'),$this->consent($agreement))->assertForbidden();
  $this->actingAs($owner)->post(route('agreement.accept'),[])->assertSessionHasErrors('agreement');
  $this->post(route('agreement.accept'),$this->consent($agreement))->assertRedirect('/')->assertSessionHasNoErrors();
  $this->assertDatabaseHas('agreement_acceptances',['organization_id'=>$org->id,'user_id'=>$owner->id,'actor_name'=>$owner->name,'sha256'=>$agreement->sha256,'source'=>'portal']);
  $this->post(route('agreement.accept'),$this->consent($agreement))->assertSessionHasNoErrors();
  $this->assertDatabaseCount('agreement_acceptances',1);
  $this->get('/')->assertOk();$this->actingAs($admin)->get('/')->assertOk();
  $this->actingAs($other)->get('/')->assertRedirect(route('agreement.required'));
 }
 public function test_tampered_hash_is_rejected_and_acceptance_survives_actor_deletion(): void {
  $owner=$this->person();$agreement=$this->publish();
  $this->actingAs($owner)->post(route('agreement.accept'),array_replace($this->consent($agreement),['agreement_hash'=>str_repeat('0',64)]))->assertSessionHasErrors('agreement');
  $this->assertDatabaseCount('agreement_acceptances',0);
  $this->post(route('agreement.accept'),$this->consent($agreement))->assertSessionHasNoErrors();
  $email=$owner->email;$owner->delete();
  $this->assertDatabaseHas('agreement_acceptances',['user_id'=>null,'actor_email'=>$email,'sha256'=>$agreement->sha256]);
 }
 public function test_suspended_owner_can_read_accept_and_manage_billing_without_reopening_access(): void {
  $owner=$this->person();$owner->organization->update(['suspended_at'=>now()]);$agreement=$this->publish();
  $this->actingAs($owner)->get(route('agreement.required'))->assertOk();
  $this->get(route('legal.agreement',$agreement))->assertOk();
  $this->post(route('agreement.accept'),$this->consent($agreement))->assertSessionHasNoErrors();
  $this->get('/abonnement')->assertOk();$this->get('/')->assertForbidden();
 }
 public function test_new_revision_preserves_old_evidence_and_rejects_stale_consent(): void {
  $owner=$this->person();$v1=$this->publish();
  $this->actingAs($owner)->post(route('agreement.accept'),$this->consent($v1))->assertSessionHasNoErrors();
  $super=$this->person(super:true);
  $data=$this->payload();$data['summary']='Ny versjon med en oppdatert beskrivelse.';
  $this->actingAs($super)->post(route('superadmin.agreements.publish'),$data)->assertSessionHasNoErrors();
  $v2=app(ServiceAgreements::class)->current();$this->assertNotSame($v1->id,$v2->id);
  $this->actingAs($owner)->get('/')->assertRedirect(route('agreement.required'));
  $this->post(route('agreement.accept'),$this->consent($v1))->assertSessionHasErrors('agreement');
  $this->post(route('agreement.accept'),$this->consent($v2))->assertSessionHasNoErrors();
  $this->assertDatabaseCount('agreement_acceptances',2);
  $this->get(route('legal.agreement',$v1))->assertOk()->assertSee($v1->sha256)->assertDontSee($data['summary']);
  $this->get('/vilkar')->assertRedirect(route('legal.agreement',$v2));
  $this->get(route('legal.agreement',$v2))->assertSee('Testleverandør AS har forhåndsgodkjent')->assertDontSee($super->name);
  $this->assertArrayNotHasKey('signer_name',$v2->content);
  $this->actingAs($super)->get(route('superadmin.agreements'))->assertOk()->assertDontSee('name="signer_name"',false);
  $this->actingAs($super)->get(route('superadmin.customer',$owner->organization))->assertOk()->assertSee('Godkjent DP-'.$v2->id)->assertSee($v1->sha256)->assertDontSee('@include',false);
  $this->expectException(\LogicException::class);$v1->update(['sha256'=>str_repeat('0',64)]);
 }
 public function test_publication_requires_superadmin_confirmed_identity_and_current_base_version(): void {
  $owner=$this->person();$data=$this->payload();
  $this->actingAs($owner)->post(route('superadmin.agreements.publish'),$data)->assertForbidden();
  $super=$this->person(super:true);$this->actingAs($super);
  $this->post(route('superadmin.agreements.publish'),array_replace($data,['confirm'=>0,'supplier_name'=>'','processors'=>'']))->assertSessionHasErrors(['confirm','supplier_name','processors']);
  $this->assertNull(app(ServiceAgreements::class)->current());
  $this->post(route('superadmin.agreements.publish'),array_diff_key($data,['base_version'=>true]))->assertSessionHasErrors('base_version');
  $this->post(route('superadmin.agreements.publish'),$data)->assertSessionHasNoErrors();
  $this->post(route('superadmin.agreements.publish'),$data)->assertSessionHasErrors('base_version');
  $this->post(route('superadmin.agreements.publish'),$this->payload())->assertSessionHasNoErrors();
  $this->assertDatabaseCount('service_agreements',1);
  $this->post(route('agreement.accept'),$this->consent(app(ServiceAgreements::class)->current()))->assertForbidden();
 }
 public function test_registration_requires_current_explicit_consent_and_stores_snapshot(): void {
  $version=$this->publish();$this->post('/logout');
  Http::fake(['data.brreg.no/enhetsregisteret/api/enheter/999999999'=>Http::response(['organisasjonsnummer'=>'999999999','navn'=>'Ny virksomhet AS'],200),'data.brreg.no/*'=>Http::response([],503)]);
  $data=['organization_number'=>'999999999','name'=>'Ny Eier','email'=>'nyeier@example.no','password'=>'TestPassword123','password_confirmation'=>'TestPassword123','eula'=>1,'privacy'=>1,'price_terms'=>1];
  $this->get('/registrer')->assertOk()->assertSee(route('legal.agreement',$version),false);
  $this->post('/registrer',$data)->assertSessionHasErrors('agreement');
  $this->assertDatabaseMissing('organizations',['organization_number'=>'999999999']);
  $this->post('/registrer',$data+$this->consent($version))->assertSessionHasNoErrors()->assertRedirect('/abonnement');
  $org=Organization::where('organization_number','999999999')->firstOrFail();
  $this->assertDatabaseHas('agreement_acceptances',['organization_id'=>$org->id,'actor_email'=>'nyeier@example.no','sha256'=>$version->sha256,'source'=>'registration']);
  $this->get('/abonnement')->assertOk();
 }
 public function test_demo_and_superadmin_do_not_accept_for_customers_and_2fa_is_preserved(): void {
  $version=$this->publish();$super=$this->person(super:true);
  $this->actingAs($super)->withSession(['superadmin_tenant_id'=>$super->organization_id])->get('/')->assertOk();
  $demo=$this->person();$demo->organization->update(['organization_number'=>'DEMO-DEKKPILOT']);
  $this->actingAs($demo)->get('/')->assertOk();
  $this->post(route('agreement.accept'),$this->consent($version))->assertForbidden();
  $owner=$this->person();$owner->update(['two_factor_confirmed_at'=>now()]);
  $this->actingAs($owner)->get(route('agreement.required'))->assertRedirect(route('two-factor.challenge'));
  $this->post(route('agreement.accept'),$this->consent($version))->assertRedirect(route('two-factor.challenge'));
  $this->assertDatabaseCount('agreement_acceptances',0);
 }
}
