<?php
namespace App\Services;
use App\Models\{Organization,ServiceAgreement,User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class ServiceAgreements
{
 public function current(): ?ServiceAgreement {
  return ServiceAgreement::find(DB::table('agreement_policy')->where('id',1)->value('agreement_id'));
 }
 public function needsAcceptance(User $user, ?ServiceAgreement $agreement): bool {
  if (!$agreement || $user->is_super_admin || !in_array($user->role,['owner','admin'],true)) return false;
  $org=Organization::find($user->organization_id);
  if (!$org || $org->organization_number==='DEMO-DEKKPILOT') return false;
  return !DB::table('agreement_acceptances')->where('organization_id',$org->id)->where('agreement_id',$agreement->id)->exists();
 }
 // Caller transaction holds the same singleton lock as publication, including registration.
 public function lockedCurrent(): ?ServiceAgreement {
  $policy=DB::table('agreement_policy')->where('id',1)->lockForUpdate()->first();
  return $policy->agreement_id ? ServiceAgreement::findOrFail($policy->agreement_id) : null;
 }
 public function validateVersion(Request $request, ?ServiceAgreement $agreement): void {
  if (!$agreement) return;
  if ((string)$request->input('agreement_id')!==(string)$agreement->id || !hash_equals($agreement->sha256,(string)$request->input('agreement_hash'))) {
   throw ValidationException::withMessages(['agreement'=>'Avtalen er oppdatert. Last siden på nytt og les den nye versjonen før du godtar.']);
  }
  if (!$request->boolean('agreement')) throw ValidationException::withMessages(['agreement'=>'Du må godta avtalen på vegne av virksomheten.']);
 }
 public function record(Request $request, User $user, ServiceAgreement $agreement, string $source): void {
  abort_unless(!$user->is_super_admin && in_array($user->role,['owner','admin'],true),403);
  $org=Organization::findOrFail($user->organization_id);
  if (DB::table('agreement_acceptances')->where('organization_id',$org->id)->where('agreement_id',$agreement->id)->exists()) return;
  DB::table('agreement_acceptances')->insert([
   'organization_id'=>$org->id,'agreement_id'=>$agreement->id,'user_id'=>$user->id,
   'actor_name'=>$user->name,'actor_email'=>$user->email,'actor_role'=>$user->role,
   'organization_name'=>$org->name,'organization_number'=>$org->organization_number,
   'sha256'=>$agreement->sha256,'source'=>$source,'ip_address'=>$request->ip(),
   'user_agent'=>Str::limit((string)$request->userAgent(),500,''),'accepted_at'=>now(),
  ]);
 }
}
