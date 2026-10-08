<?php
namespace Tests\Feature;

use App\Models\{Organization,Branch,User};
use App\Services\BrregService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationProfileTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(): array {
        $org=Organization::create(['public_id'=>Str::uuid(),'name'=>'Verksted','organization_number'=>'999999999','subscription_status'=>'active','profile'=>['address'=>'Manuell vei 9','billing_address'=>'Faktura 1']]);
        $branch=Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'name'=>'Hoved','code'=>'H']);
        $user=User::factory()->create(['organization_id'=>$org->id,'branch_id'=>$branch->id,'role'=>'owner','active'=>true]);
        return [$org,$user];
    }
    private function fake(): void {
        Http::preventStrayRequests();
        Http::fake([
            '*/enheter/999999999'=>Http::response(['navn'=>'Register AS','forretningsadresse'=>['adresse'=>['Registerveien 2'],'postnummer'=>'0123','poststed'=>'OSLO'],'postadresse'=>['adresse'=>['Postboks 8'],'postnummer'=>'0124','poststed'=>'OSLO']]),
            '*/enheter/999999999/roller'=>Http::response(['rollegrupper'=>[['type'=>['beskrivelse'=>'Styret'],'roller'=>[['type'=>['kode'=>'LEDE'],'person'=>['navn'=>['fornavn'=>'Kari','etternavn'=>'Test'],'fodselsdato'=>'1980-01-01','fodselsnummer'=>'01018012345']]]]]]),
            '*/fullmakt/enheter/999999999/*'=>Http::response(['status'=>['regelStatus'=>['kode'=>'OK']],'signeringsKombinasjon'=>['kombinasjon'=>[['tekstforklaring'=>'To i fellesskap','personRolleKombinasjon'=>[['navn'=>'Kari Test','fodselsdato'=>'1980-01-01']]]]]]),
        ]);
    }
    public function test_refresh_is_selective_and_private_registry_data_is_sanitized_and_encrypted(): void {
        [$org,$user]=$this->fixture();$this->fake();$this->actingAs($user);
        $this->post(route('admin.organization.brreg'),['apply'=>['postal']])->assertSessionHasNoErrors();
        $org->refresh();
        $this->assertSame('Verksted',$org->name);
        $this->assertSame('Manuell vei 9',$org->profile['address']);
        $this->assertSame('Faktura 1',$org->profile['billing_address']);
        $this->assertSame('Postboks 8',$org->profile['postal_address']);
        $private=json_encode($org->brreg_private_data);
        $this->assertStringContainsString('Kari',$private);
        $this->assertStringContainsString('To i fellesskap',$private);
        $this->assertStringNotContainsString('1980-01-01',$private);
        $this->assertStringNotContainsString('01018012345',$private);
        $this->assertStringNotContainsString('Kari',DB::table('organizations')->where('id',$org->id)->value('brreg_private_data'));
        $this->assertArrayNotHasKey('brreg_private_data',$org->toArray());
        $this->get(route('admin.organization'))->assertOk()->assertDontSee('Kari');
        $user->update(['is_super_admin'=>true]);
        $this->get(route('superadmin.customer',$org))->assertOk()->assertSee('Kari')->assertSee('To i fellesskap');
    }
    public function test_manual_update_cannot_change_org_number_private_data_or_other_tenant(): void {
        [$org,$user]=$this->fixture();$other=Organization::create(['public_id'=>Str::uuid(),'name'=>'Annen']);
        $this->actingAs($user)->put(route('admin.organization.update'),[
            'name'=>'Eget navn','organization_id'=>$other->id,'organization_number'=>'123456789','brreg_private_data'=>['fake'=>'secret'],
            'profile'=>['address'=>'Ny vei','billing_email'=>'faktura@example.no','untrusted'=>'no'],
        ])->assertSessionHasNoErrors();
        $org->refresh();
        $this->assertSame('999999999',$org->organization_number);
        $this->assertSame('Annen',$other->fresh()->name);
        $this->assertNull($org->brreg_private_data);
        $this->assertArrayNotHasKey('untrusted',$org->profile);
        $this->assertSame('Ny vei',$org->profile['address']);
        $this->put(route('admin.organization.update'),['name'=>'X','profile'=>['billing_email'=>'bad']])->assertSessionHasErrors('profile.billing_email');
        $user->update(['role'=>'technician']);
        $this->get(route('admin.organization'))->assertForbidden();
        $this->post(route('admin.organization.brreg'))->assertForbidden();
    }
    public function test_unavailable_brreg_preserves_manual_and_previous_registry_information(): void {
        [$org,$user]=$this->fixture();
        $org->update(['brreg_data'=>['name'=>'Old'],'brreg_private_data'=>['prokura'=>['data'=>['status'=>['code'=>'old']],'fetched_at'=>now()->subDay()->toIso8601String()]]]);
        Http::fake(['*'=>Http::response([],503)]);
        $this->actingAs($user)->post(route('admin.organization.brreg'),['apply'=>['business']])->assertSessionHasErrors('brreg');
        $this->assertSame('Old',$org->fresh()->brreg_data['name']);
        $this->assertSame('Manuell vei 9',$org->fresh()->profile['address']);
        $result=app(BrregService::class)->privateDetails('999999999',$org->brreg_private_data);
        $this->assertFalse($result['prokura']['available']);
        $this->assertSame('old',$result['prokura']['data']['status']['code']);
    }
    public function test_registration_saves_addresses_and_optional_roles_without_exposing_them_publicly(): void {
        $this->fake();
        $this->getJson(route('register.lookup',['organization_number'=>'999999999']))->assertOk()->assertJsonPath('postal_address','Postboks 8')->assertDontSee('Kari');
        $this->post('/registrer',['organization_number'=>'999999999','name'=>'Owner','email'=>'new@example.no','password'=>'SecurePass12345','password_confirmation'=>'SecurePass12345','eula'=>1,'privacy'=>1,'price_terms'=>1])->assertSessionHasNoErrors();
        $org=Organization::where('organization_number','999999999')->firstOrFail();
        $this->assertSame('Registerveien 2',$org->profile['address']);
        $this->assertArrayNotHasKey('billing_address',$org->profile);
        $this->assertTrue($org->brreg_private_data['roles']['available']);
    }
    public function test_grouped_admin_preserves_existing_destinations(): void {
        [$org,$user]=$this->fixture();$this->actingAs($user);
        $page=$this->get(route('admin'))->assertOk();
        foreach(['admin.organization','admin.labels','admin.warehouse','admin.settings','admin.tires','admin.imports','admin.accounting','admin.payments','admin.communications','admin.system','admin.security','admin.portals','billing'] as $route) $page->assertSee(route($route),false);
        $this->withSession(['demo_read_only'=>true])->put(route('admin.organization.update'),['name'=>'Wrong','profile'=>[]])->assertSessionHasErrors('demo');
        $this->assertSame('Verksted',$org->fresh()->name);
    }

    public function test_refresh_keeps_legacy_address_when_no_fields_are_selected(): void {
        [$org,$user]=$this->fixture();
        $org->update(['profile'=>null,'brreg_data'=>['address'=>'Tidligere adresse','city'=>'BERGEN']]);
        $this->fake();
        $this->actingAs($user)->post(route('admin.organization.brreg'))->assertSessionHasNoErrors();
        $this->assertSame('Tidligere adresse',$org->fresh()->profile['address']);
        $this->assertSame('Registerveien 2',$org->fresh()->brreg_data['address']);
    }
}
