<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Support\Facades\DB;
return new class extends Migration{
 public function up():void{$demoOrganizations=DB::table('organizations')->where('organization_number','DEMO-DEKKPILOT')->pluck('id');DB::table('customers')->whereNull('postal_code')->where(function($query)use($demoOrganizations){$query->whereIn('organization_id',$demoOrganizations)->orWhere('notes','like','[DUMMY]%')->orWhere('notes','like','[DEMO-BULK]%');})->update(['postal_code'=>'0182','city'=>'OSLO','updated_at'=>now()]);}
 public function down():void{}
};
