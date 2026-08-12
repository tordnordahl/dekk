<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::create('outbound_messages',function(Blueprint $t){$t->id();$t->uuid('public_id')->unique();$t->foreignId('organization_id')->constrained()->cascadeOnDelete();$t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();$t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$t->enum('channel',['email','sms']);$t->enum('purpose',['transactional','marketing'])->default('transactional');$t->string('recipient');$t->string('subject')->nullable();$t->text('body');$t->enum('status',['queued','processing','sent','failed','cancelled'])->default('queued');$t->unsignedTinyInteger('attempts')->default(0);$t->timestamp('scheduled_at')->useCurrent();$t->timestamp('sent_at')->nullable();$t->timestamp('failed_at')->nullable();$t->text('last_error')->nullable();$t->string('provider_reference')->nullable();$t->timestamps();$t->index(['status','scheduled_at']);});
  Schema::table('bookings',function(Blueprint $t){$t->enum('confirmation_status',['not_required','pending','confirmed','declined'])->default('pending')->after('status');$t->string('confirmation_token_hash',64)->nullable()->unique();$t->timestamp('confirmation_requested_at')->nullable();$t->timestamp('confirmation_reminder_sent_at')->nullable();$t->timestamp('confirmation_deadline_at')->nullable();$t->timestamp('confirmation_responded_at')->nullable();});
 }
 public function down():void{Schema::table('bookings',function(Blueprint $t){$t->dropUnique(['confirmation_token_hash']);$t->dropColumn(['confirmation_status','confirmation_token_hash','confirmation_requested_at','confirmation_reminder_sent_at','confirmation_deadline_at','confirmation_responded_at']);});Schema::dropIfExists('outbound_messages');}
};
