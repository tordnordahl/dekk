<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('service_agreements', function(Blueprint $t) {
   $t->id(); $t->json('content'); $t->string('sha256',64); $t->unsignedBigInteger('published_by'); $t->timestamp('published_at');
  });
  Schema::create('agreement_policy', function(Blueprint $t) {
   $t->unsignedInteger('id')->primary(); $t->foreignId('agreement_id')->nullable()->constrained('service_agreements');
  });
  DB::table('agreement_policy')->insert(['id'=>1,'agreement_id'=>null]);
  Schema::create('agreement_acceptances', function(Blueprint $t) {
   $t->id(); $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
   $t->foreignId('agreement_id')->constrained('service_agreements');
   $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
   $t->string('actor_name'); $t->string('actor_email'); $t->string('actor_role',40);
   $t->string('organization_name'); $t->string('organization_number',32)->nullable();
   $t->string('sha256',64); $t->string('source',20); $t->string('ip_address',45)->nullable();
   $t->string('user_agent',500)->nullable(); $t->timestamp('accepted_at');
   $t->unique(['organization_id','agreement_id']);
  });
 }
 public function down(): void {
  Schema::dropIfExists('agreement_acceptances'); Schema::dropIfExists('agreement_policy'); Schema::dropIfExists('service_agreements');
 }
};
