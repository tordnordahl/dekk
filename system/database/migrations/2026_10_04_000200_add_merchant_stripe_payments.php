<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('checkout_payments',function(Blueprint $t) {
            $t->string('stripe_checkout_session_id')->nullable()->unique();
            $t->uuid('stripe_checkout_key')->nullable();
            $t->string('stripe_account_id')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('checkout_payments',function(Blueprint $t) {
            $t->dropUnique(['stripe_checkout_session_id']);
            $t->dropColumn(['stripe_checkout_session_id','stripe_checkout_key','stripe_account_id']);
        });
    }
};
