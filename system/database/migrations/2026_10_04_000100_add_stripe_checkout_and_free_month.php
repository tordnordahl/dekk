<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->uuid('stripe_checkout_key')->nullable();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->uuid('stripe_free_month_key')->nullable();
            $table->timestamp('stripe_free_month_granted_at')->nullable();
            $table->timestamp('stripe_free_month_applied_at')->nullable();
            $table->boolean('stripe_cancel_at_period_end')->default(false);
        });
        Schema::create('subscription_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('grant_key');
            $table->timestamp('granted_at');
            $table->timestamp('seen_at')->nullable();
            $table->unique(['user_id','grant_key']);
            $table->index(['user_id','seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_notices');
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn([
            'stripe_checkout_key', 'stripe_checkout_session_id', 'stripe_free_month_key', 'stripe_free_month_granted_at',
            'stripe_free_month_applied_at', 'stripe_cancel_at_period_end',
        ]));
    }
};
