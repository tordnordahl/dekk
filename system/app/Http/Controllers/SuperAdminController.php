<?php
namespace App\Http\Controllers;
use App\Models\Organization;use App\Services\DatabaseUpdateStatus;use Illuminate\Http\RedirectResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;use Illuminate\View\View;
class SuperAdminController extends Controller
{
 public function index(Request $request,DatabaseUpdateStatus $updates):View{$query=Organization::query()->with(['users'=>fn($q)=>$q->orderByDesc('role')])->withCount(['users','customers','vehicles','bookings','tireSets']);if($search=trim((string)$request->query('q')))$query->where(fn($q)=>$q->where('name','like',"%{$search}%")->orWhere('organization_number','like',"%{$search}%")->orWhereHas('users',fn($u)=>$u->where('email','like',"%{$search}%")));if(in_array($request->query('status'),['active','trialing','past_due','incomplete','canceled','unpaid','paused'],true))$query->where('subscription_status',$request->query('status'));if($request->query('access')==='closed')$query->whereNotNull('suspended_at');if($request->query('access')==='open')$query->whereNull('suspended_at');return view('superadmin.index',['organizations'=>$query->latest()->paginate(30)->withQueryString(),'stats'=>['organizations'=>Organization::count(),'active'=>Organization::whereIn('subscription_status',['active','trialing'])->count(),'attention'=>Organization::whereIn('subscription_status',['past_due','unpaid','incomplete'])->count(),'closed'=>Organization::whereNotNull('suspended_at')->count()],'updateStatus'=>$updates->inspect()]);}
 public function show(Organization $organization):View
 {
  $currentAgreement=app(\App\Services\ServiceAgreements::class)->current();
  $acceptances=DB::table('agreement_acceptances')->where('organization_id',$organization->id)->orderByDesc('accepted_at')->get();
  $currentAcceptance=$currentAgreement?$acceptances->firstWhere('agreement_id',$currentAgreement->id):null;
  return view('superadmin.customer',['currentAgreement'=>$currentAgreement,'acceptances'=>$acceptances,'currentAcceptance'=>$currentAcceptance,'legacyAcceptances'=>DB::table('legal_acceptances')->where('organization_id',$organization->id)->orderByDesc('accepted_at')->get(),'organization'=>$organization->load('users')->loadCount(['users','customers','vehicles','tireSets']),
   'history'=>DB::table('audit_logs')->where('subject_type',Organization::class)->where('subject_id',$organization->id)->latest('id')->limit(15)->get()]);
 }
 public function update(Request $request,Organization $organization):RedirectResponse
 {
  $data=$request->validate(['name'=>['required','string','max:255'],'organization_number'=>['nullable','string','max:32',\Illuminate\Validation\Rule::unique('organizations')->ignore($organization->id)],'email'=>['nullable','email','max:255'],'phone'=>['nullable','string','max:32']]);
  if (($data['organization_number']??null)!==$organization->organization_number && ($organization->organization_number==='DEMO-DEKKPILOT'||($data['organization_number']??null)==='DEMO-DEKKPILOT')) return back()->withErrors(['organization_number'=>'Demoens organisasjonsnummer er reservert.']);
  DB::transaction(function() use($request,$organization,$data){
   if (($data['organization_number']??null)!==$organization->organization_number) $data+=['brreg_verified_at'=>null,'brreg_data'=>null,'brreg_private_data'=>null];
   $organization->update($data);$this->audit($request,$organization,'superadmin.customer.updated');
  });
  return back()->with('success','Kundeopplysningene er lagret i DekkPilot. Fakturaopplysninger hos Stripe administreres separat.');
 }
 public function access(Request $request,Organization $organization):RedirectResponse
 {
  $data=$request->validate(['closed'=>['required','boolean'],'reason'=>['nullable','string','max:500'],'confirm'=>['accepted']]);
  DB::transaction(function() use($request,$organization,$data){
   $organization->update(['suspended_at'=>$data['closed']?now():null,'suspension_reason'=>$data['closed']?($data['reason']??null):null]);
   $this->audit($request,$organization,$data['closed']?'superadmin.customer.closed':'superadmin.customer.reopened');
  });
  return back()->with('success',$data['closed']?'Tilgangen er stengt. Stripe-abonnementet er ikke sagt opp.':'Stengingen er fjernet. Vanlige abonnementskrav gjelder fortsatt.');
 }
 private function audit(Request $request,Organization $organization,string $action):void
 {
  DB::table('audit_logs')->insert(['organization_id'=>$organization->id,'user_id'=>$request->user()->id,'action'=>$action,'subject_type'=>Organization::class,'subject_id'=>$organization->id,'ip_address'=>$request->ip(),'created_at'=>now()]);
 }
 public function enter(Request $request,Organization $organization):RedirectResponse{if(!$organization->branches()->where('active',true)->exists())return back()->withErrors(['tenant'=>'Virksomheten har ingen aktiv avdeling.']);$request->session()->put('superadmin_tenant_id',$organization->id);DB::table('audit_logs')->insert(['organization_id'=>$organization->id,'user_id'=>$request->user()->id,'action'=>'superadmin.tenant.entered','subject_type'=>Organization::class,'subject_id'=>$organization->id,'ip_address'=>$request->ip(),'created_at'=>now()]);return redirect()->route('dashboard')->with('success','Du arbeider nå i '.$organization->name.'.');}
 public function leave(Request $request):RedirectResponse{$request->session()->forget('superadmin_tenant_id');return redirect()->route('superadmin')->with('success','Du er tilbake i plattformoversikten.');}
}
