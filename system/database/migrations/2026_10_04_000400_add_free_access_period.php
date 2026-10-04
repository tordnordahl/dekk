<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('organizations',function(Blueprint $t){$t->timestamp('free_access_started_at')->nullable();$t->timestamp('free_access_until')->nullable();$t->uuid('free_access_grant_key')->nullable();});}
 public function down():void {Schema::table('organizations',fn(Blueprint $t)=>$t->dropColumn(['free_access_started_at','free_access_until','free_access_grant_key']));}
};
