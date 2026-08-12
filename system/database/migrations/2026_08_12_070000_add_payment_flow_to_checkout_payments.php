<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('checkout_payments', function (Blueprint $table) {
            $table->string('payment_method', 30)->nullable()->after('provider');
            $table->string('provider_status', 60)->nullable()->after('provider_reference');
            $table->string('receipt_channel', 20)->nullable()->after('paid_at');
            $table->string('receipt_recipient')->nullable()->after('receipt_channel');
            $table->timestamp('receipt_sent_at')->nullable()->after('receipt_recipient');
            $table->json('provider_payload')->nullable()->after('last_error');
            $table->index(['organization_id', 'payment_method', 'status'], 'checkout_payment_method_status');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_payments', function (Blueprint $table) {
            $table->dropIndex('checkout_payment_method_status');
            $table->dropColumn(['payment_method', 'provider_status', 'receipt_channel', 'receipt_recipient', 'receipt_sent_at', 'provider_payload']);
        });
    }
};
