<?php
namespace App\Services;
use App\Models\Customer;use App\Models\Organization;use App\Models\User;
class TestDataGuard{
 public function organization(int $organizationId):bool{return Organization::whereKey($organizationId)->where('organization_number','DEMO-DEKKPILOT')->exists();}
 public function customer(?Customer $customer):bool{return $customer!==null&&($this->organization($customer->organization_id)||str_starts_with((string)$customer->notes,'[DUMMY]'));}
 public function email(string $email):bool{$email=strtolower(trim($email));return User::whereRaw('LOWER(email)=?',[$email])->whereHas('organization',fn($q)=>$q->where('organization_number','DEMO-DEKKPILOT'))->exists()||Customer::whereRaw('LOWER(email)=?',[$email])->where(fn($q)=>$q->where('notes','like','[DUMMY]%')->orWhereHas('organization',fn($org)=>$org->where('organization_number','DEMO-DEKKPILOT')))->exists();}
}
