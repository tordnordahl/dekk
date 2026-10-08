<?php
namespace App\Http\Controllers;
use App\Models\ServiceAgreement;
use App\Services\ServiceAgreements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
class ServiceAgreementController extends Controller
{
 public function document(ServiceAgreement $agreement) { return view('legal.agreement',compact('agreement')); }
 public function required(Request $request, ServiceAgreements $service) {
  $agreement=$service->current();
  if (!$service->needsAcceptance($request->user(),$agreement)) return redirect()->route('dashboard');
  return view('legal.accept',['agreement'=>$agreement,'organization'=>$request->user()->organization]);
 }
 public function accept(Request $request, ServiceAgreements $service) {
  abort_unless(!$request->user()->is_super_admin && in_array($request->user()->role,['owner','admin'],true),403);
  abort_if($request->session()->get('demo_read_only') || $request->user()->organization?->organization_number==='DEMO-DEKKPILOT',403);
  $request->validate(['agreement'=>['accepted']]);
  DB::transaction(function() use($request,$service) {
   $agreement=$service->lockedCurrent(); abort_unless($agreement,409);
   $service->validateVersion($request,$agreement);
   $service->record($request,$request->user(),$agreement,'portal');
  });
  return redirect()->route('dashboard')->with('success','Avtalen er godkjent for virksomheten.');
 }
 public function edit(ServiceAgreements $service) {
  return view('superadmin.agreements',['agreement'=>$service->current(),'draft'=>require resource_path('legal/agreement-draft.php')]);
 }
 public function publish(Request $request, ServiceAgreements $service) {
  $data=$request->validate([
   'supplier_name'=>['required','string','max:255'],'supplier_number'=>['required','regex:/^\d{9}$/'],
   'supplier_address'=>['required','string','max:500'],'supplier_email'=>['required','email','max:255'],
   'summary'=>['required','string','max:1500'],'terms'=>['required','string','min:100','max:60000'],
   'dpa'=>['required','string','min:100','max:60000'],'processors'=>['required','string','min:20','max:15000'],
   'deletion'=>['required','string','min:20','max:5000'],'confirm'=>['accepted'],
  ]);
  unset($data['confirm']);
  DB::transaction(function() use($request,$service,$data) {
   $current=$service->lockedCurrent();
   Validator::make(['base_version'=>$request->input('base_version')],['base_version'=>['required','integer',function($attribute,$value,$fail) use($current) {
    if ((string)$value!==(string)($current?->id??0)) $fail('En annen versjon er publisert i mellomtiden. Last siden på nytt.');
   }]])->validate();
   $hash=hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
   if ($current && hash_equals($current->sha256,$hash)) return;
   $version=ServiceAgreement::create(['content'=>$data,'sha256'=>$hash,'published_by'=>$request->user()->id,'published_at'=>now()]);
   DB::table('agreement_policy')->where('id',1)->update(['agreement_id'=>$version->id]);
  });
  return back()->with('success','Avtalen er publisert. Eier eller administrator må godta versjonen neste gang portalen åpnes.');
 }
}
