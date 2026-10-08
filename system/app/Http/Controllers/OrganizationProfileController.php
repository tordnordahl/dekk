<?php
namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\BrregService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrganizationProfileController extends Controller
{
    public function show(Request $request)
    {
        return view('admin.organization',['organization'=>$request->user()->organization]);
    }

    public function update(Request $request)
    {
        $data=$request->validate([
            'name'=>['required','string','max:255'],'email'=>['nullable','email','max:255'],'phone'=>['nullable','string','max:32'],
            'profile'=>['required','array'],
            'profile.address'=>['nullable','string','max:500'],'profile.postal_code'=>['nullable','string','max:20'],
            'profile.city'=>['nullable','string','max:100'],'profile.country'=>['nullable','string','size:2'],
            'profile.postal_address'=>['nullable','string','max:500'],'profile.postal_postal_code'=>['nullable','string','max:20'],
            'profile.postal_city'=>['nullable','string','max:100'],'profile.postal_country'=>['nullable','string','size:2'],
            'profile.billing_address'=>['nullable','string','max:500'],'profile.billing_postal_code'=>['nullable','string','max:20'],
            'profile.billing_city'=>['nullable','string','max:100'],'profile.billing_country'=>['nullable','string','size:2'],
            'profile.billing_email'=>['nullable','email','max:255'],'profile.billing_reference'=>['nullable','string','max:100'],
            'profile.website'=>['nullable','string','max:255'],
        ]);
        $allowed=['address','postal_code','city','country','postal_address','postal_postal_code','postal_city','postal_country',
            'billing_address','billing_postal_code','billing_city','billing_country','billing_email','billing_reference','website'];
        $data['profile']=array_intersect_key($data['profile'],array_flip($allowed));
        $organization=$request->user()->organization;
        DB::transaction(function() use($organization,$data,$request) {
            $organization->update($data);
            $this->audit($request,$organization,'organization.profile.updated');
        });
        return back()->with('success','Virksomhetsopplysningene er lagret i DekkPilot. Fakturadetaljer hos Stripe eller regnskapssystemet endres ikke automatisk.');
    }

    public function refresh(Request $request, BrregService $brreg)
    {
        $data=$request->validate(['apply'=>['nullable','array'],'apply.*'=>['in:name,business,postal,phone']]);
        $organization=$request->user()->organization;
        try { $verified=$brreg->lookup((string)$organization->organization_number); }
        catch(RuntimeException $e) { throw ValidationException::withMessages(['brreg'=>$e->getMessage()]); }
        $private=$brreg->privateDetails($verified['organization_number'],$organization->brreg_private_data??[]);
        $apply=$data['apply']??[];
        DB::transaction(function() use($request,$organization,$verified,$private,$apply,$brreg) {
            $organization->refresh();
            $changes=['brreg_data'=>$verified,'brreg_private_data'=>$private,'brreg_verified_at'=>now()];
            $profile=$organization->profile??$brreg->profile($organization->brreg_data??[]);
            $registryProfile=$brreg->profile($verified);
            foreach(['business'=>['address','postal_code','city','country'],'postal'=>['postal_address','postal_postal_code','postal_city','postal_country']] as $group=>$fields) {
                if(in_array($group,$apply,true)) {
                    foreach($fields as $field) if(filled($registryProfile[$field]??null)) $profile[$field]=$registryProfile[$field];
                }
            }
            if(in_array('name',$apply,true)) $changes['name']=$verified['name'];
            if(in_array('phone',$apply,true)&&filled($verified['phone']??null)) $changes['phone']=$verified['phone'];
            $changes['profile']=$profile;
            $organization->update($changes);
            $this->audit($request,$organization,'organization.brreg.refreshed');
        });
        return back()->with('success','Registeropplysningene er hentet. Bare feltene du valgte er oppdatert; fakturaopplysningene er beholdt.');
    }

    private function audit(Request $request, Organization $organization,string $action): void
    {
        DB::table('audit_logs')->insert(['organization_id'=>$organization->id,'user_id'=>$request->user()->id,'action'=>$action,
            'subject_type'=>Organization::class,'subject_id'=>$organization->id,'ip_address'=>$request->ip(),'created_at'=>now()]);
    }
}
