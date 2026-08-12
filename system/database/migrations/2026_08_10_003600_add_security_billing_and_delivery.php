<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('remember_token');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->string('delivery_status',32)->nullable()->after('provider_reference');
            $table->timestamp('delivered_at')->nullable()->after('delivery_status');
            $table->timestamp('bounced_at')->nullable()->after('delivered_at');
            $table->json('provider_metadata')->nullable()->after('bounced_at');
        });
        Schema::create('billing_statements', function (Blueprint $table) {
            $table->id();$table->uuid('public_id')->unique();$table->foreignId('organization_id')->constrained()->cascadeOnDelete();$table->date('period_start');$table->date('period_end');$table->unsignedInteger('subscription_cents')->default(11900);$table->unsignedInteger('sms_quantity')->default(0);$table->unsignedInteger('sms_unit_price_cents')->default(0);$table->unsignedInteger('sms_total_cents')->default(0);$table->unsignedInteger('discount_cents')->default(0);$table->unsignedInteger('total_cents')->default(11900);$table->enum('status',['draft','ready','invoiced','void'])->default('draft');$table->string('external_reference')->nullable();$table->timestamp('finalized_at')->nullable();$table->timestamps();$table->unique(['organization_id','period_start']);$table->index(['status','period_start']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('billing_statements');Schema::table('outbound_messages',fn(Blueprint $table)=>$table->dropColumn(['delivery_status','delivered_at','bounced_at','provider_metadata']));Schema::table('users',fn(Blueprint $table)=>$table->dropColumn(['two_factor_secret','two_factor_recovery_codes','two_factor_confirmed_at']));
    }
};
