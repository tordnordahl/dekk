<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\DB;use Illuminate\Support\Facades\Schema;
return new class extends Migration{public function up():void{Schema::table('users',function(Blueprint $t){$t->boolean('is_super_admin')->default(false)->after('active')->index();});DB::table('users')->whereRaw('LOWER(email) = ?', ['tordgladnordahl@gmail.com'])->update(['is_super_admin'=>true]);}public function down():void{Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('is_super_admin'));}};
