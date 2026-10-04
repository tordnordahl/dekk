<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('organizations', function(Blueprint $t) {
   $t->timestamp('suspended_at')->nullable(); $t->string('suspension_reason',500)->nullable();
   $t->unsignedTinyInteger('stripe_free_month_count')->default(1);
   $t->json('stripe_latest_invoice')->nullable(); $t->timestamp('stripe_synced_at')->nullable();
  });
  Schema::table('subscription_notices', fn(Blueprint $t)=>$t->unsignedTinyInteger('months')->default(1));
 }
 public function down(): void {
  Schema::table('subscription_notices', fn(Blueprint $t)=>$t->dropColumn('months'));
  Schema::table('organizations', fn(Blueprint $t)=>$t->dropColumn(['suspended_at','suspension_reason','stripe_free_month_count','stripe_latest_invoice','stripe_synced_at']));
 }
};
